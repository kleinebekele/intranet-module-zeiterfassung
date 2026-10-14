<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Intranet\Modules\Zeiterfassung\Models\Abteilung;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Besetzung;
use Intranet\Modules\Zeiterfassung\Models\Dienstplan;
use Intranet\Modules\Zeiterfassung\Models\Schicht;
use Intranet\Modules\Zeiterfassung\Models\Sondertag;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;

/**
 * Dienstplanung einer Abteilung für einen Monat.
 *
 * Bedarf: Aus den Besetzungsregeln entsteht je Tag ein 15-Minuten-Raster mit
 * „mindestens" und „gewünscht" (= mindestens + optional). Gesetzliche Feiertage
 * (Bundesland der Abteilung) sind geschlossen, Sondertage gehen vor.
 *
 * Soll: Tagessoll aus Zeitmodell bzw. Wochenstunden. Feiertage und Abwesenheiten
 * werden gutgeschrieben (Soll 0) – dem Mitarbeiter fehlt dadurch nichts.
 *
 * Vorschlag je Kalenderwoche in drei Schritten:
 *  1. Tage und Stunden der Stammkräfte: das Wochensoll auf so viele Tage, wie die
 *     Person je Woche arbeitet, dort, wo am meisten Bedarf offen ist (Samstage reihum).
 *     Kurze Tage gleichen längere Tage aus, bis zur Öffnungszeit bzw. Tageshöchstzeit.
 *  2. Uhrzeiten: Wer bisher seltener früh war, öffnet; wer seltener spät war, schließt.
 *  3. Lücken: Öffnungszeit/Mindestbesetzung immer – angrenzende Schicht verlängern,
 *     sonst Aushilfe im Budget, sonst Stammkraft an ihrem freien Tag. Was über das
 *     Wochensoll geht, markiert die Prüfung als Überstunden. Optionale Besetzung nur
 *     mit Aushilfen-Budget.
 *
 * Gleich gute Möglichkeiten entscheidet das Los: „Neu vorschlagen" liefert jedes Mal eine
 * andere Variante.
 *
 * Immer: Ruhezeit (§ 5), Tageshöchstzeit (§ 3), Pausen (§ 4), höchstens sechs
 * Tage am Stück, keine Schicht an Abwesenheits- oder festen freien Tagen.
 */
class Planer
{
    public const RASTER = 15;

    /** Abwesenheiten, die einen Tag blockieren – beantragter Urlaub auch, damit er nicht verplant wird. */
    private const BLOCKIERT = [Abwesenheit::GENEHMIGT, Abwesenheit::BEANTRAGT];

    /** @var Collection<int, Besetzung> */
    private Collection $besetzung;

    /** @var array<string, Sondertag> */
    private array $sondertage;

    /** @var array<int, array<string, mixed>> user_id => Planungsdaten */
    private array $personen = [];

    /** @var array<string, array<string, mixed>> Y-m-d => Tagesbedarf */
    private array $tage = [];

    /** @var array<int, array<int, array{von: int, bis: int}>> Schichten vor dem Monat und aus anderen Abteilungen: user => tagIndex => Zeit */
    private array $fremd = [];

    private int $ruhezeit;

    private int $maxTag;

    public Carbon $bis;

    public function __construct(public Abteilung $abteilung, public Carbon $monat)
    {
        $this->monat = $monat->copy()->startOfMonth()->startOfDay();
        $this->bis = $this->monat->copy()->endOfMonth()->startOfDay();
        $this->ruhezeit = Einstellungen::int('ruhezeit');
        $this->maxTag = Einstellungen::int('max_tag');

        $this->besetzung = $abteilung->besetzung()->get();
        $this->sondertage = $abteilung->sondertage()
            ->whereDate('datum', '>=', $this->monat->toDateString())
            ->whereDate('datum', '<=', $this->bis->toDateString())
            ->get()->keyBy(fn (Sondertag $s) => $s->datum->toDateString())->all();

        for ($d = $this->monat->copy(); $d->lte($this->bis); $d->addDay()) {
            $this->tage[$d->toDateString()] = $this->bedarf($d->copy());
        }

        $this->ladePersonen();
    }

    // ── Zeit-Helfer ────────────────────────────────────────────────

    public static function minute(?string $uhrzeit): int
    {
        if (! $uhrzeit || ! preg_match('/^(\d{1,2}):(\d{2})/', $uhrzeit, $m)) {
            return 0;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function uhrzeit(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /** Gesetzliche Pause für eine Anwesenheit (§ 4 ArbZG): über 6 Std. Arbeit 30 Min., über 9 Std. 45 Min. */
    public static function pauseFuer(int $anwesenheit): int
    {
        return match (true) {
            $anwesenheit <= 360 => 0,
            $anwesenheit <= 570 => 30,
            default => 45,
        };
    }

    public static function anwesenheitFuer(int $arbeit): int
    {
        return $arbeit + ($arbeit > 540 ? 45 : ($arbeit > 360 ? 30 : 0));
    }

    private function index(Carbon|string $tag): int
    {
        $tag = $tag instanceof Carbon ? $tag : Carbon::parse($tag);

        // Gerundet: über die Zeitumstellung hat ein Tag 23 oder 25 Stunden.
        return (int) round($this->monat->diffInDays($tag->copy()->startOfDay(), false));
    }

    // ── Bedarf ─────────────────────────────────────────────────────

    /**
     * @return array{datum: Carbon, offen: bool, hinweis: ?string, feiertag: ?string, sondertag: ?string,
     *               slots: array<int, array{min: int, wunsch: int}>, start: int, ende: int}
     */
    public function bedarf(Carbon $tag): array
    {
        $feiertag = Feiertage::name($tag, $this->abteilung->land());
        $sonder = $this->sondertage[$tag->toDateString()] ?? null;

        $fenster = [];
        if ($sonder) {
            if (! $sonder->geschlossen && $sonder->von && $sonder->bis) {
                $fenster[] = ['von' => $sonder->von, 'bis' => $sonder->bis, 'min' => $sonder->mindestens, 'opt' => $sonder->optional];
            }
        } elseif (! $feiertag) {
            foreach ($this->besetzung->where('wochentag', $tag->dayOfWeekIso) as $b) {
                $fenster[] = ['von' => $b->von, 'bis' => $b->bis, 'min' => $b->mindestens, 'opt' => $b->optional];
            }
        }

        $slots = [];
        foreach ($fenster as $f) {
            for ($m = self::minute($f['von']); $m < self::minute($f['bis']); $m += self::RASTER) {
                $slots[$m]['min'] = max($slots[$m]['min'] ?? 0, (int) $f['min']);
                $slots[$m]['wunsch'] = max($slots[$m]['wunsch'] ?? 0, (int) $f['min'] + (int) $f['opt']);
            }
        }
        $slots = array_filter($slots, fn ($s) => $s['wunsch'] > 0);
        ksort($slots);

        return [
            'datum' => $tag,
            'offen' => $slots !== [],
            'feiertag' => $feiertag,
            'sondertag' => $sonder?->bezeichnung,
            'hinweis' => $sonder?->bezeichnung ?? $feiertag,
            'slots' => $slots,
            'start' => $slots ? array_key_first($slots) : 0,
            'ende' => $slots ? array_key_last($slots) + self::RASTER : 0,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function tage(): array
    {
        return $this->tage;
    }

    // ── Personen ───────────────────────────────────────────────────

    private function ladePersonen(): void
    {
        $leute = $this->abteilung->personen()->get();
        $ids = $leute->pluck('id')->all();
        $land = $this->abteilung->land();

        $modelle = Zeitmodell::whereIn('user_id', $ids)->orderBy('gueltig_ab')->orderBy('id')->get()->groupBy('user_id');
        $abwesenheiten = Abwesenheit::whereIn('user_id', $ids)->whereIn('status', self::BLOCKIERT)
            ->ueberschneidet($this->monat, $this->bis)->get()->groupBy('user_id');

        // Schichten der Vorwoche (Ruhezeit, Tage am Stück) und aus anderen Abteilungen (gleicher Tag = belegt).
        Schicht::whereIn('user_id', $ids)
            ->whereDate('datum', '>=', $this->monat->copy()->subDays(7)->toDateString())
            ->whereDate('datum', '<=', $this->bis->toDateString())
            ->whereHas('dienstplan', fn ($q) => $q->where(fn ($q) => $q->where('abteilung_id', '!=', $this->abteilung->id)
                ->orWhereDate('monat', '!=', $this->monat->toDateString())))
            ->get()
            ->each(function (Schicht $s) {
                $this->fremd[$s->user_id][$this->index($s->datum)] = ['von' => self::minute($s->von), 'bis' => self::minute($s->bis)];
            });

        foreach ($leute as $user) {
            $pivot = $user->pivot;
            $liste = $modelle->get($user->id, collect());
            $modell = Zeitmodell::amTag($liste, $this->bis) ?? Zeitmodell::amTag($liste, $this->monat);

            $wochen = $pivot->wochenstunden !== null ? (int) $pivot->wochenstunden : ($modell?->wochenMinuten() ?? 0);
            $tageWoche = (int) ($pivot->max_tage_woche ?: ($modell?->arbeitstageJeWoche() ?: 5));
            $tageWoche = max(1, min(6, $tageWoche));
            $aushilfe = (bool) $pivot->aushilfe || $wochen === 0;

            $abwesend = [];
            foreach ($abwesenheiten->get($user->id, collect()) as $a) {
                for ($d = $a->von->copy(); $d->lte($a->bis); $d->addDay()) {
                    $abwesend[$d->toDateString()] = $a->artText().($a->status === Abwesenheit::BEANTRAGT ? ' (beantragt)' : '');
                }
            }

            // Tagessoll: mit Zeitmodell genau dessen Soll (wie die Zeiterfassung rechnet), sonst die
            // Wochenstunden gleich auf die ersten Wochentage verteilt (5 Tage = Mo–Fr). Feiertage und
            // Abwesenheiten werden gutgeschrieben: Sie mindern das Soll, im Konto fehlt nichts.
            $sollTag = [];
            $gutschrift = 0;
            if (! $aushilfe) {
                for ($d = $this->monat->copy(); $d->lte($this->bis); $d->addDay()) {
                    $soll = $pivot->wochenstunden === null && $modell
                        ? (Zeitmodell::amTag($liste, $d) ?? $modell)->sollFuer($d->dayOfWeekIso)
                        : ($d->dayOfWeekIso <= $tageWoche ? intdiv($wochen, $tageWoche) : 0);
                    if (Feiertage::name($d, $land)) {
                        $gutschrift += $soll;
                        $soll = 0;
                    } elseif (isset($abwesend[$d->toDateString()])) {
                        $soll = 0;
                    }
                    $sollTag[$d->toDateString()] = $soll;
                }
            }
            $ziel = array_sum($sollTag);

            $tagArbeit = $aushilfe ? 0 : min($this->maxTag, intdiv($wochen, $tageWoche));

            $this->personen[$user->id] = [
                'user' => $user,
                'aushilfe' => $aushilfe,
                'ohneStunden' => ! $pivot->aushilfe && $wochen === 0,
                'wochen' => $wochen,
                'tageWoche' => $aushilfe ? (int) ($pivot->max_tage_woche ?: 6) : $tageWoche,
                'tagArbeit' => $tagArbeit,
                'ziel' => $ziel,
                'sollTag' => $sollTag,
                'feiertagGutschrift' => $gutschrift,
                'budget' => $pivot->max_monat !== null ? (int) $pivot->max_monat : null,
                'frei' => array_map('intval', array_filter(explode(',', (string) $pivot->freie_tage))),
                'abwesend' => $abwesend,
                'modell' => $modell,
            ];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function personen(): array
    {
        return $this->personen;
    }

    // ── Vorschlag ──────────────────────────────────────────────────

    /** @var array<int, array<int, array{von: int, bis: int}>> user => tagIndex => Schicht (dieser Plan) */
    private array $plan = [];

    /** @var array<string, array<int, int>> Y-m-d => slot => Anzahl */
    private array $abdeckung = [];

    /** @var array<int, int> Früh minus Spät je Person – hält den Wechsel ausgeglichen */
    private array $fruehSpaet = [];

    /** @var array<int, int> Samstage je Person */
    private array $samstage = [];

    /**
     * Ohne $seed bei jedem Aufruf eine andere, gleich gute Variante (Gleichstände entscheidet das Los).
     *
     * @return list<array{user_id: int, datum: string, von: string, bis: string, pause: int}>
     */
    public function vorschlagen(?int $seed = null): array
    {
        mt_srand($seed ?? random_int(1, PHP_INT_MAX));

        $this->plan = [];
        $this->abdeckung = [];
        foreach ($this->tage as $datum => $t) {
            $this->abdeckung[$datum] = array_fill_keys(array_keys($t['slots']), 0);
        }
        $stamm = array_keys(array_filter($this->personen, fn ($p) => ! $p['aushilfe']));
        $this->fruehSpaet = array_fill_keys($stamm, 0);
        $this->samstage = array_fill_keys($stamm, 0);

        foreach ($this->wochen() as $woche) {
            $arbeit = $this->wocheVerteilen($woche, $stamm);
            foreach ($woche as $datum) {
                $this->uhrzeitenSetzen($datum, $arbeit[$datum] ?? []);
            }
        }
        $this->luecken('min');
        $this->luecken('wunsch');

        $ergebnis = [];
        foreach ($this->plan as $uid => $tage) {
            foreach ($tage as $i => $s) {
                $ergebnis[] = [
                    'user_id' => $uid,
                    'datum' => $this->monat->copy()->addDays($i)->toDateString(),
                    'von' => self::uhrzeit($s['von']),
                    'bis' => self::uhrzeit($s['bis']),
                    'pause' => self::pauseFuer($s['bis'] - $s['von']),
                ];
            }
        }

        return $ergebnis;
    }

    /** @return list<list<string>> Kalenderwochen des Monats (nur Tage im Monat) */
    public function wochen(): array
    {
        $wochen = [];
        foreach ($this->tage as $datum => $t) {
            $wochen[$t['datum']->isoFormat('GGGG-WW')][] = $datum;
        }

        return array_values($wochen);
    }

    /**
     * Schritt 1 je Woche: Welche Stammkraft arbeitet an welchen Tagen wie lange?
     * Ziel ist das Wochensoll (Feiertage und Abwesenheiten sind schon herausgerechnet),
     * verteilt auf so viele Tage, wie die Person je Woche arbeitet. Kurze Tage (Samstag)
     * gleichen längere Tage aus – bis zur Öffnungszeit und höchstens zur Tageshöchstzeit.
     *
     * @param  list<string>  $woche
     * @param  list<int>  $stamm
     * @return array<string, array<int, int>> Y-m-d => user => Arbeitsminuten
     */
    private function wocheVerteilen(array $woche, array $stamm): array
    {
        $kapazitaet = array_fill_keys($woche, 0);
        $bedarf = [];
        foreach ($woche as $datum) {
            $slots = $this->tage[$datum]['slots'];
            $bedarf[$datum] = 3 * array_sum(array_column($slots, 'min')) * self::RASTER
                + array_sum(array_column($slots, 'wunsch')) * self::RASTER;
        }

        $ziel = [];
        $tageSoll = [];
        $tage = [];
        foreach ($stamm as $uid) {
            $p = $this->personen[$uid];
            $ziel[$uid] = array_sum(array_map(fn ($d) => $p['sollTag'][$d] ?? 0, $woche));
            $moeglich = array_filter($woche, fn ($d) => $this->tage[$d]['offen'] && $this->frei($uid, $this->tage[$d]['datum']));
            // Tage dieser Kalenderwoche aus dem Vormonat zählen mit.
            $schonDa = $this->wochenTage($uid, $this->tage[$woche[0]]['datum']);
            $hoechstens = min(count($moeglich), max(0, $p['tageWoche'] - $schonDa));
            $n = $ziel[$uid] > 0 ? (int) round($ziel[$uid] / max(1, $p['tagArbeit'])) : 0;
            $tageSoll[$uid] = min($hoechstens, max($n, (int) ceil($ziel[$uid] / max(1, $this->maxTag))));
            $tage[$uid] = [];
        }

        // Tage reihum vergeben: wo am meisten Bedarf offen ist; Samstage reihum; bei Gleichstand das Los.
        do {
            $weiter = false;
            $reihe = $stamm;
            shuffle($reihe);
            usort($reihe, fn ($a, $b) => (count($tage[$a]) / max(1, $tageSoll[$a])) <=> (count($tage[$b]) / max(1, $tageSoll[$b])));
            foreach ($reihe as $uid) {
                if (count($tage[$uid]) >= $tageSoll[$uid]) {
                    continue;
                }
                $bester = null;
                $besterWert = null;
                foreach ($woche as $datum) {
                    $t = $this->tage[$datum];
                    if (! $t['offen'] || isset($tage[$uid][$datum]) || ! $this->frei($uid, $t['datum'])) {
                        continue;
                    }
                    if ($this->amStueck($uid, $t['datum'], $tage[$uid]) > 6) {
                        continue;
                    }
                    $wert = $bedarf[$datum] - $kapazitaet[$datum];
                    if ($t['datum']->dayOfWeekIso === 6 && $this->samstage[$uid] > min($this->samstage)) {
                        $wert -= 10000;
                    }
                    $wert += mt_rand(0, 59);
                    if ($besterWert === null || $wert > $besterWert) {
                        [$bester, $besterWert] = [$datum, $wert];
                    }
                }
                if ($bester !== null) {
                    $tage[$uid][$bester] = true;
                    $kapazitaet[$bester] += 3 * self::anwesenheitFuer($this->personen[$uid]['tagArbeit']);
                    if ($this->tage[$bester]['datum']->dayOfWeekIso === 6) {
                        $this->samstage[$uid]++;
                    }
                    $weiter = true;
                }
            }
        } while ($weiter);

        // Stunden auf die Tage verteilen (Wasserstand: wer gedeckelt ist, gibt den Rest an die anderen).
        $ergebnis = [];
        foreach ($stamm as $uid) {
            $offen = array_keys($tage[$uid]);
            $rest = $ziel[$uid];
            $deckel = [];
            foreach ($offen as $d) {
                $span = $this->tage[$d]['ende'] - $this->tage[$d]['start'];
                $deckel[$d] = min($this->maxTag, $span - self::pauseFuer($span));
            }
            while ($offen) {
                $anteil = $rest / count($offen);
                $knapp = array_values(array_filter($offen, fn ($d) => $deckel[$d] < $anteil));
                if (! $knapp) {
                    foreach ($offen as $nr => $d) {
                        // auf Viertelstunden runden, Rundungsrest an den letzten Tag
                        $minuten = $nr === count($offen) - 1 ? $rest : $anteil;
                        $minuten = (int) round($minuten / self::RASTER) * self::RASTER;
                        $ergebnis[$d][$uid] = min($deckel[$d], max(self::RASTER, $minuten));
                        $rest -= $ergebnis[$d][$uid];
                    }
                    break;
                }
                foreach ($knapp as $d) {
                    $ergebnis[$d][$uid] = $deckel[$d];
                    $rest -= $deckel[$d];
                }
                $offen = array_values(array_diff($offen, $knapp));
            }
        }

        return $ergebnis;
    }

    /**
     * Schritt 2: Uhrzeiten eines Tages. Wer bisher seltener früh war, beginnt mit der
     * Öffnung; wer seltener spät war, schließt. Weitere Personen dorthin, wo am meisten fehlt.
     *
     * @param  array<int, int>  $arbeit  user => Arbeitsminuten
     */
    private function uhrzeitenSetzen(string $datum, array $arbeit): void
    {
        $t = $this->tage[$datum];
        if (! $arbeit || ! $t['offen']) {
            return;
        }
        $reihe = array_keys($arbeit);
        shuffle($reihe);
        usort($reihe, fn ($a, $b) => $this->fruehSpaet[$a] <=> $this->fruehSpaet[$b]);

        $span = $t['ende'] - $t['start'];
        foreach ($reihe as $nr => $uid) {
            $laenge = min($span, self::anwesenheitFuer($arbeit[$uid]));
            $rolle = match (true) {
                count($reihe) === 1 => ($this->fruehSpaet[$uid] < 0 || ($this->fruehSpaet[$uid] === 0 && mt_rand(0, 1) === 1)) ? 'frueh' : 'spaet',
                $nr === 0 => 'frueh',
                $nr === count($reihe) - 1 => 'spaet',
                default => 'mitte',
            };
            $this->setzeSchicht($uid, $t, $laenge, $rolle);
        }
    }

    private function setzeSchicht(int $uid, array $t, int $laenge, string $rolle): void
    {
        $i = $this->index($t['datum']);
        $kandidaten = [];
        for ($start = $t['start']; $start + $laenge <= $t['ende']; $start += self::RASTER) {
            if ($this->ruheOk($uid, $i, $start, $start + $laenge)) {
                $kandidaten[] = $start;
            }
        }
        if (! $kandidaten) {
            return;
        }

        $start = match ($rolle) {
            'frueh' => $kandidaten[0],
            'spaet' => $kandidaten[count($kandidaten) - 1],
            default => $this->besteLage($t, $kandidaten, $laenge),
        };
        $this->eintragen($uid, $t, $start, $start + $laenge);

        if ($laenge < $t['ende'] - $t['start']) {
            $this->fruehSpaet[$uid] += $start === $t['start'] ? 1 : ($start + $laenge === $t['ende'] ? -1 : 0);
        }
    }

    /** @param  list<int>  $kandidaten */
    private function besteLage(array $t, array $kandidaten, int $laenge): int
    {
        $datum = $t['datum']->toDateString();
        $bester = $kandidaten[0];
        $besterWert = -1;
        foreach ($kandidaten as $start) {
            $wert = mt_rand(0, 2);
            for ($m = $start; $m < $start + $laenge; $m += self::RASTER) {
                if ($s = $t['slots'][$m] ?? null) {
                    $ist = $this->abdeckung[$datum][$m];
                    $wert += $ist < $s['min'] ? 30 : ($ist < $s['wunsch'] ? 10 : 0);
                }
            }
            if ($wert > $besterWert) {
                [$bester, $besterWert] = [$start, $wert];
            }
        }

        return $bester;
    }

    /**
     * Schritt 3: offene Viertelstunden auffüllen – 'min' = Öffnungszeit/Mindestbesetzung,
     * 'wunsch' = optionale Besetzung.
     *
     * Mindestbesetzung immer: erst eine angrenzende Schicht verlängern (bis zur Tageshöchstzeit),
     * dann eine Aushilfe im Budget, dann eine Stammkraft an ihrem freien Tag. Was dabei über das
     * Wochensoll hinausgeht, markiert die Prüfung als Überstunden.
     */
    private function luecken(string $stufe): void
    {
        $minSchicht = max(self::RASTER, (int) $this->abteilung->min_schicht);

        foreach ($this->tage as $datum => $t) {
            if (! $t['offen']) {
                continue;
            }
            $ab = $t['start'];
            for ($runde = 0; $runde < 50; $runde++) {
                $luecke = $this->naechsteLuecke($datum, $stufe, $ab);
                if ($luecke === null) {
                    break;
                }
                [$von, $bis] = $luecke;

                if ($stufe === 'min' && $this->verlaengern($t, $von, $bis)) {
                    continue;
                }

                $laenge = min(max($bis - $von, $minSchicht), $t['ende'] - $t['start'], self::anwesenheitFuer($this->maxTag));
                $start = $von + $laenge > $t['ende'] ? max($t['start'], $t['ende'] - $laenge) : $von;

                $uid = $this->kandidatFuerLuecke($t, $start, $start + $laenge, $stufe, $minSchicht);
                if ($uid === null) {
                    $ab = $bis; // nicht zu füllen – weiter hinten suchen
                    continue;
                }

                $p = $this->personen[$uid];
                if ($p['aushilfe'] && $p['budget'] !== null) {
                    $rest = $p['budget'] - $this->geplant($uid);
                    $laenge = min($laenge, intdiv(self::anwesenheitFuer($rest), self::RASTER) * self::RASTER);
                    if ($laenge < self::RASTER) {
                        $ab = $bis;
                        continue;
                    }
                    $start = $von + $laenge > $t['ende'] ? max($t['start'], $t['ende'] - $laenge) : $von;
                }
                $this->eintragen($uid, $t, $start, $start + $laenge);
            }
        }
    }

    /** Eine Schicht desselben Tages, die an die Lücke grenzt, über die Lücke verlängern. */
    private function verlaengern(array $t, int $von, int $bis): bool
    {
        $i = $this->index($t['datum']);
        $wahl = null;
        foreach ($this->plan as $uid => $tage) {
            $s = $tage[$i] ?? null;
            if (! $s || $this->personen[$uid]['aushilfe']) {
                continue;
            }
            $neu = null;
            if ($s['von'] < $von && $s['bis'] >= $von && $s['bis'] < $bis) {
                $neu = ['von' => $s['von'], 'bis' => $bis];
            } elseif ($s['bis'] > $bis && $s['von'] <= $bis && $s['von'] > $von) {
                $neu = ['von' => $von, 'bis' => $s['bis']];
            }
            if (! $neu) {
                continue;
            }
            $dauer = $neu['bis'] - $neu['von'];
            if ($dauer - self::pauseFuer($dauer) > $this->maxTag || ! $this->ruheOk($uid, $i, $neu['von'], $neu['bis'])) {
                continue;
            }
            $ueber = $this->geplant($uid) - $this->personen[$uid]['ziel'];
            if ($wahl === null || $ueber < $wahl[2]) {
                $wahl = [$uid, $neu, $ueber];
            }
        }
        if (! $wahl) {
            return false;
        }

        [$uid, $neu] = $wahl;
        $alt = $this->plan[$uid][$i];
        $datum = $t['datum']->toDateString();
        for ($m = $alt['von']; $m < $alt['bis']; $m += self::RASTER) {
            if (isset($this->abdeckung[$datum][$m])) {
                $this->abdeckung[$datum][$m]--;
            }
        }
        $this->eintragen($uid, $t, $neu['von'], $neu['bis']);

        return true;
    }

    /** @return array{0: int, 1: int}|null */
    private function naechsteLuecke(string $datum, string $stufe, int $ab): ?array
    {
        $von = null;
        $letzter = null;
        foreach ($this->tage[$datum]['slots'] as $m => $s) {
            if ($m < $ab) {
                continue;
            }
            $fehlt = $this->abdeckung[$datum][$m] < $s[$stufe];
            if ($fehlt && $von === null) {
                $von = $m;
            } elseif (! $fehlt && $von !== null) {
                return [$von, $m];
            }
            $letzter = $m;
        }

        return $von !== null ? [$von, $letzter + self::RASTER] : null;
    }

    /** Aushilfe mit Budget; für die Mindestbesetzung notfalls eine Stammkraft an ihrem freien Tag (Überstunden). */
    private function kandidatFuerLuecke(array $t, int $von, int $bis, string $stufe, int $minSchicht): ?int
    {
        $i = $this->index($t['datum']);
        $arbeit = $bis - $von - self::pauseFuer($bis - $von);

        $passt = function (int $uid, bool $wochenGrenze = true) use ($t, $i, $von, $bis): bool {
            $p = $this->personen[$uid];

            return ! isset($this->plan[$uid][$i])
                && $this->frei($uid, $t['datum'])
                && (! $wochenGrenze || $this->wochenTage($uid, $t['datum']) < $p['tageWoche'])
                && $this->amStueck($uid, $t['datum']) <= 6
                && $this->ruheOk($uid, $i, $von, $bis);
        };

        $aushilfen = array_filter(array_keys($this->personen), function ($uid) use ($passt, $minSchicht, $arbeit, $stufe) {
            $p = $this->personen[$uid];
            if (! $p['aushilfe'] || ! $passt($uid)) {
                return false;
            }
            if ($p['budget'] === null) {
                // Stammkräfte ohne Wochenstunden springen nur für die Mindestbesetzung ein.
                return $stufe === 'min' || ! $p['ohneStunden'];
            }
            $rest = $p['budget'] - $this->geplant($uid);

            // Für die optionale Besetzung nur, wenn die ganze Schicht ins Budget passt.
            return $stufe === 'min' ? $rest >= min($arbeit, $minSchicht) : $rest >= $arbeit;
        });
        $aushilfen = array_values($aushilfen);
        shuffle($aushilfen);
        usort($aushilfen, fn ($a, $b) => $this->geplant($a) <=> $this->geplant($b));
        if ($aushilfen) {
            return $aushilfen[0];
        }

        if ($stufe !== 'min') {
            return null;
        }

        // Öffnungszeit geht vor: erst innerhalb der Tage je Woche, sonst auch darüber hinaus.
        foreach ([true, false] as $grenze) {
            $stamm = array_values(array_filter(array_keys($this->personen), fn ($uid) => ! $this->personen[$uid]['aushilfe'] && $passt($uid, $grenze)));
            usort($stamm, fn ($a, $b) => ($this->geplant($a) - $this->personen[$a]['ziel']) <=> ($this->geplant($b) - $this->personen[$b]['ziel']));
            if ($stamm) {
                return $stamm[0];
            }
        }

        return null;
    }

    private function eintragen(int $uid, array $t, int $von, int $bis): void
    {
        $datum = $t['datum']->toDateString();
        $this->plan[$uid][$this->index($t['datum'])] = ['von' => $von, 'bis' => $bis];
        for ($m = $von; $m < $bis; $m += self::RASTER) {
            if (isset($this->abdeckung[$datum][$m])) {
                $this->abdeckung[$datum][$m]++;
            }
        }
    }

    // ── Regeln ─────────────────────────────────────────────────────

    /** Darf die Person an dem Tag überhaupt eingeplant werden? */
    private function frei(int $uid, Carbon $tag): bool
    {
        $p = $this->personen[$uid];

        return ! isset($p['abwesend'][$tag->toDateString()])
            && ! in_array($tag->dayOfWeekIso, $p['frei'], true)
            && ! isset($this->fremd[$uid][$this->index($tag)]);
    }

    private function schichtAm(int $uid, int $i): ?array
    {
        return $this->plan[$uid][$i] ?? $this->fremd[$uid][$i] ?? null;
    }

    private function arbeitetAm(int $uid, int $i, ?array $arbeitstage): bool
    {
        if ($arbeitstage !== null && $i >= 0) {
            if (isset($arbeitstage[$this->monat->copy()->addDays($i)->toDateString()])) {
                return true;
            }
        }

        return $this->schichtAm($uid, $i) !== null;
    }

    /** Arbeitstage in der Kalenderwoche des Tages, wenn er dazukäme. */
    private function wochenTage(int $uid, Carbon $tag, ?array $arbeitstage = null): int
    {
        $i = $this->index($tag);
        $montag = $i - ($tag->dayOfWeekIso - 1);
        $n = 0;
        for ($j = $montag; $j < $montag + 7; $j++) {
            if ($j !== $i && $this->arbeitetAm($uid, $j, $arbeitstage)) {
                $n++;
            }
        }

        return $n;
    }

    /** Länge der Arbeitsserie, wenn der Tag dazukäme. */
    private function amStueck(int $uid, Carbon $tag, ?array $arbeitstage = null): int
    {
        $i = $this->index($tag);
        $n = 1;
        for ($j = $i - 1; $j >= $i - 7 && $this->arbeitetAm($uid, $j, $arbeitstage); $j--) {
            $n++;
        }
        for ($j = $i + 1; $j <= $i + 7 && $this->arbeitetAm($uid, $j, $arbeitstage); $j++) {
            $n++;
        }

        return $n;
    }

    private function ruheOk(int $uid, int $i, int $von, int $bis): bool
    {
        $vorher = $this->schichtAm($uid, $i - 1);
        $nachher = $this->schichtAm($uid, $i + 1);

        return (! $vorher || $von + 1440 - $vorher['bis'] >= $this->ruhezeit)
            && (! $nachher || $nachher['von'] + 1440 - $bis >= $this->ruhezeit);
    }

    /** Geplante Arbeitsminuten (ohne Pausen) im Vorschlag. */
    private function geplant(int $uid): int
    {
        $summe = 0;
        foreach ($this->plan[$uid] ?? [] as $s) {
            $summe += $s['bis'] - $s['von'] - self::pauseFuer($s['bis'] - $s['von']);
        }

        return $summe;
    }

    // ── Prüfung eines (auch von Hand geänderten) Plans ─────────────

    /**
     * @param  Collection<int, Schicht>  $schichten
     * @return array{
     *     tage: array<string, array{abdeckung: array<int, int>, fehlt: list<string>, optional: list<string>}>,
     *     personen: array<int, array{geplant: int, ziel: int, budget: ?int, warnungen: list<string>}>,
     *     zellen: array<int, array<string, list<string>>>
     * }
     */
    public function pruefen(Collection $schichten): array
    {
        $this->plan = [];
        $ergebnis = ['tage' => [], 'personen' => [], 'zellen' => [], 'ueberstunden' => [], 'wochen' => []];

        $abdeckung = [];
        foreach ($this->tage as $datum => $t) {
            $abdeckung[$datum] = array_fill_keys(array_keys($t['slots']), 0);
        }

        foreach ($schichten as $s) {
            $datum = $s->datum->toDateString();
            $von = self::minute($s->von);
            $bis = self::minute($s->bis);
            $this->plan[$s->user_id][$this->index($s->datum)] = ['von' => $von, 'bis' => $bis];
            for ($m = $von; $m < $bis; $m += self::RASTER) {
                if (isset($abdeckung[$datum][$m])) {
                    $abdeckung[$datum][$m]++;
                }
            }
        }

        foreach ($this->tage as $datum => $t) {
            $ergebnis['tage'][$datum] = [
                'abdeckung' => $abdeckung[$datum],
                'fehlt' => self::unterdeckung($t['slots'], $abdeckung[$datum], 'min'),
                'optional' => self::unterdeckung($t['slots'], $abdeckung[$datum], 'wunsch'),
            ];
        }

        $proSchicht = $schichten->groupBy('user_id');
        foreach ($this->personen as $uid => $p) {
            $warnungen = [];
            $geplant = 0;
            foreach ($proSchicht->get($uid, collect()) as $s) {
                $datum = $s->datum->toDateString();
                $i = $this->index($s->datum);
                $zelle = [];
                $geplant += $s->arbeit();

                if (isset($p['abwesend'][$datum])) {
                    $zelle[] = 'Abwesend: '.$p['abwesend'][$datum];
                }
                if (in_array($s->datum->dayOfWeekIso, $p['frei'], true)) {
                    $zelle[] = 'Fester freier Tag';
                }
                if ($s->arbeit() > $this->maxTag) {
                    $zelle[] = 'Mehr als '.Format::dauer($this->maxTag).' Std. Arbeit (§ 3 ArbZG)';
                }
                if ((int) $s->pause < self::pauseFuer($s->dauer())) {
                    $zelle[] = 'Pause zu kurz: '.(int) $s->pause.' statt '.self::pauseFuer($s->dauer()).' Min. (§ 4 ArbZG)';
                }
                $vortag = $this->schichtAm($uid, $i - 1);
                if ($vortag && self::minute($s->von) + 1440 - $vortag['bis'] < $this->ruhezeit) {
                    $zelle[] = 'Ruhezeit zum Vortag unter '.Format::dauer($this->ruhezeit).' Std. (§ 5 ArbZG)';
                }
                if ($t = $this->tage[$datum] ?? null) {
                    if ($t['feiertag'] || $s->datum->dayOfWeekIso === 7) {
                        $zelle[] = $t['feiertag'] ? "Feiertag ({$t['feiertag']})" : 'Sonntag';
                    }
                }
                if (isset($this->fremd[$uid][$i])) {
                    $zelle[] = 'Am selben Tag in einer anderen Abteilung eingeplant';
                }
                if ($zelle) {
                    $ergebnis['zellen'][$uid][$datum] = $zelle;
                }
            }

            for ($d = $this->monat->copy(); $d->lte($this->bis); $d->addDay()) {
                $i = $this->index($d);
                if (isset($this->plan[$uid][$i]) && ! isset($this->plan[$uid][$i - 1]) && $this->amStueck($uid, $d) > 6) {
                    $warnungen[] = 'Ab '.$d->isoFormat('dd DD.MM.').' mehr als 6 Tage am Stück';
                }
                if ($d->dayOfWeekIso === 7 || $d->eq($this->bis)) {
                    $tage = 0;
                    for ($j = $i - $d->dayOfWeekIso + 1; $j <= $i; $j++) {
                        $tage += isset($this->plan[$uid][$j]) || isset($this->fremd[$uid][$j]) ? 1 : 0;
                    }
                    if ($tage > $p['tageWoche']) {
                        $warnungen[] = 'KW '.$d->isoWeek.": {$tage} Arbeitstage statt höchstens {$p['tageWoche']}";
                    }
                }
            }

            // Überstunden je Kalenderwoche: was über das Wochensoll hinausgeht, an den letzten Schichten der Woche markieren.
            $eigene = $proSchicht->get($uid, collect())->keyBy(fn (Schicht $s) => $s->datum->toDateString());
            foreach ($this->wochen() as $woche) {
                $soll = array_sum(array_map(fn ($d) => $p['sollTag'][$d] ?? 0, $woche));
                $ist = array_sum(array_map(fn ($d) => $eigene->get($d)?->arbeit() ?? 0, $woche));
                $ergebnis['wochen'][$uid][end($woche)] = ['soll' => $soll, 'ist' => $ist];
            }
            if (! $p['aushilfe']) {
                foreach ($this->wochen() as $woche) {
                    $soll = array_sum(array_map(fn ($d) => $p['sollTag'][$d] ?? 0, $woche));
                    $ist = array_sum(array_map(fn ($d) => $eigene->get($d)?->arbeit() ?? 0, $woche));
                    $mehr = $ist - $soll;
                    if ($mehr < self::RASTER) {
                        continue;
                    }
                    $warnungen[] = 'KW '.$this->tage[$woche[0]]['datum']->isoWeek.': '.Format::dauer($mehr, true).' Std. Überstunden';
                    foreach (array_reverse($woche) as $d) {
                        if ($mehr <= 0) {
                            break;
                        }
                        if ($s = $eigene->get($d)) {
                            $ergebnis['ueberstunden'][$uid][$d] = min($mehr, $s->arbeit());
                            $mehr -= $s->arbeit();
                        }
                    }
                }
            }

            if ($p['budget'] !== null && $geplant > $p['budget']) {
                $warnungen[] = 'Budget überschritten: '.Format::dauer($geplant).' von '.Format::dauer($p['budget']).' Std.';
            }
            if (! $p['aushilfe'] && $p['ziel'] > 0 && abs($geplant - $p['ziel']) > 60) {
                $warnungen[] = ($geplant > $p['ziel'] ? 'Über' : 'Unter').' Soll: '.Format::dauer($geplant - $p['ziel'], true).' Std.';
            }

            $ergebnis['personen'][$uid] = [
                'geplant' => $geplant,
                'ziel' => $p['ziel'],
                'gutschrift' => $p['feiertagGutschrift'] ?? 0,
                'budget' => $p['budget'],
                'warnungen' => $warnungen,
            ];
        }

        return $ergebnis;
    }

    /**
     * Zusammenhängende Zeiträume, in denen weniger Personen eingeplant sind als nötig.
     *
     * @param  array<int, array{min: int, wunsch: int}>  $slots
     * @param  array<int, int>  $abdeckung
     * @return list<string> z. B. „17:00–19:00 nur 1 von 2"
     */
    private static function unterdeckung(array $slots, array $abdeckung, string $stufe): array
    {
        $liste = [];
        $lauf = null;
        $schliessen = function () use (&$lauf, &$liste) {
            if ($lauf) {
                $liste[] = self::uhrzeit($lauf['von']).'–'.self::uhrzeit($lauf['bis'])." nur {$lauf['ist']} von {$lauf['soll']}";
            }
            $lauf = null;
        };

        foreach ($slots as $m => $s) {
            $ist = $abdeckung[$m] ?? 0;
            if ($ist >= $s[$stufe]) {
                $schliessen();
                continue;
            }
            if ($lauf && $lauf['bis'] === $m && $lauf['ist'] === $ist && $lauf['soll'] === $s[$stufe]) {
                $lauf['bis'] = $m + self::RASTER;
                continue;
            }
            $schliessen();
            $lauf = ['von' => $m, 'bis' => $m + self::RASTER, 'ist' => $ist, 'soll' => $s[$stufe]];
        }
        $schliessen();

        return $liste;
    }

    /** Plan anlegen bzw. alle Schichten durch einen neuen Vorschlag ersetzen. */
    public function speichernAlsVorschlag(?int $von): Dienstplan
    {
        $plan = Dienstplan::fuer($this->abteilung, $this->monat)
            ?? Dienstplan::create(['abteilung_id' => $this->abteilung->id, 'monat' => $this->monat, 'status' => Dienstplan::ENTWURF, 'erstellt_von' => $von]);

        $vorschlag = $this->vorschlagen();
        \Illuminate\Support\Facades\DB::transaction(function () use ($plan, $vorschlag, $von) {
            $plan->schichten()->delete();
            foreach ($vorschlag as $s) {
                $plan->schichten()->create($s);
            }
            $plan->update(['erstellt_von' => $von]);
        });

        return $plan;
    }
}
