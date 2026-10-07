<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use Intranet\Modules\Zeiterfassung\Models\Konto;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;

/**
 * Rechnet Soll, Ist, Pausen und Saldo einer Person für einen Zeitraum und
 * prüft dabei das Arbeitszeitgesetz. Lädt alles einmal und rechnet im Speicher.
 *
 * Regeln:
 *  - Pausen sind die Lücken zwischen zwei Abschnitten desselben Tages. Als
 *    Ruhepause zählen nur Lücken ab 15 Minuten (§ 4 ArbZG).
 *  - Über 6 Std. Arbeit sind 30 Min. Pause nötig, über 9 Std. 45 Min. Fehlt
 *    Pause, wird sie (Einstellung) abgezogen – höchstens bis auf die Schwelle,
 *    damit 6:10 Std. ohne Pause nicht zu 5:40 Std. werden.
 *  - Ein Abschnitt zählt zu dem Tag, an dem er beginnt (Nachtschicht).
 *  - Ohne Zeitmodell gibt es kein Soll und keinen Saldo.
 */
class Rechner
{
    /** @var Collection<int, Zeitmodell> */
    public Collection $modelle;

    /** @var Collection<int, Buchung> */
    private Collection $buchungen;

    /** @var Collection<int, Abwesenheit> */
    private Collection $abwesenheiten;

    private ?Carbon $jetzt;

    public function __construct(public User $user, public Carbon $von, public Carbon $bis, ?Carbon $jetzt = null)
    {
        $this->von = $von->copy()->startOfDay();
        $this->bis = $bis->copy()->startOfDay();
        $this->jetzt = $jetzt ?? now();

        $this->modelle = Zeitmodell::where('user_id', $user->id)->orderBy('gueltig_ab')->orderBy('id')->get();

        // Einen Tag davor mitladen: Ruhezeit-Prüfung braucht das Ende des Vortags.
        $this->buchungen = Buchung::gueltig()->with('terminal')->where('user_id', $user->id)
            ->zwischen($this->von->copy()->subDay(), $this->bis)
            ->orderBy('beginn')->get();

        $this->abwesenheiten = Abwesenheit::genehmigt()->where('user_id', $user->id)
            ->ueberschneidet($this->von, $this->bis)->get();
    }

    /** @return list<Tag> */
    public function tage(): array
    {
        $tage = [];
        for ($d = $this->von->copy(); $d->lte($this->bis); $d->addDay()) {
            $tage[] = $this->tag($d->copy());
        }

        return $tage;
    }

    public function tag(Carbon $datum): Tag
    {
        $tag = new Tag($datum->copy()->startOfDay());
        $modell = Zeitmodell::amTag($this->modelle, $datum);
        $land = $modell?->bundesland ?: Einstellungen::get('bundesland');

        $tag->feiertag = Feiertage::name($datum, $land);
        $tag->mitModell = $modell !== null;
        $tag->soll = ($modell && ! $tag->feiertag) ? $modell->sollFuer($datum->dayOfWeekIso) : 0;

        $tag->abwesenheit = $this->abwesenheiten->first(fn (Abwesenheit $a) => $a->umfasst($datum));
        if ($tag->abwesenheit && $tag->abwesenheit->schreibtGut()) {
            $tag->gutschrift = $tag->abwesenheit->halbtag ? intdiv($tag->soll, 2) : $tag->soll;
        }

        $d = $datum->toDateString();
        $tag->abschnitte = $this->buchungen->filter(fn (Buchung $b) => $b->beginn->toDateString() === $d)->values();

        $this->rechneArbeit($tag);
        $this->pruefe($tag);

        return $tag;
    }

    private function rechneArbeit(Tag $tag): void
    {
        $vorher = null;
        foreach ($tag->abschnitte as $b) {
            $tag->arbeit += $b->minuten($this->jetzt);
            $tag->offen = $tag->offen || $b->istOffen();

            if ($vorher?->ende) {
                $luecke = max(0, intdiv($b->beginn->getTimestamp() - $vorher->ende->getTimestamp(), 60));
                if ($luecke > 0) {
                    $tag->pausen[] = ['von' => $vorher->ende, 'bis' => $b->beginn, 'minuten' => $luecke, 'zaehlt' => $luecke >= 15];
                    if ($luecke >= 15) {
                        $tag->pause += $luecke;
                    }
                }
            }
            $vorher = $b;
        }

        $schwelle = $tag->arbeit > 540 ? 540 : ($tag->arbeit > 360 ? 360 : null);
        $tag->pauseNoetig = $schwelle === 540 ? 45 : ($schwelle === 360 ? 30 : 0);

        $fehlend = max(0, $tag->pauseNoetig - $tag->pause);
        if ($fehlend > 0 && ! $tag->offen && Einstellungen::bool('pause_abziehen')) {
            $tag->abzug = min($fehlend, $tag->arbeit - $schwelle);
        }
    }

    private function pruefe(Tag $tag): void
    {
        if ($tag->abschnitte->isEmpty()) {
            return;
        }

        $fehlend = $tag->pauseNoetig - $tag->pause;
        if ($fehlend > 0 && ! $tag->offen) {
            $tag->warnungen['pause'] = "Pause zu kurz: {$tag->pause} von {$tag->pauseNoetig} Min. (§ 4 ArbZG)";
        }

        $block = self::laengsterBlock($tag->abschnitte, $this->jetzt);
        $maxBlock = Einstellungen::int('max_block');
        if ($block > $maxBlock) {
            $tag->warnungen['block'] = 'Mehr als '.Format::dauer($maxBlock).' Std. am Stück ohne Pause ('.Format::dauer($block).', § 4 ArbZG)';
        }

        $maxTag = Einstellungen::int('max_tag');
        if ($tag->ist() > $maxTag) {
            $tag->warnungen['max_tag'] = 'Mehr als '.Format::dauer($maxTag).' Std. gearbeitet ('.Format::dauer($tag->ist()).', § 3 ArbZG)';
        }

        $erster = $tag->abschnitte->first();
        $davor = $this->buchungen->filter(fn (Buchung $b) => $b->beginn->lt($tag->datum) && $b->ende !== null)->last();
        if ($davor) {
            $ruhe = intdiv($erster->beginn->getTimestamp() - $davor->ende->getTimestamp(), 60);
            $minRuhe = Einstellungen::int('ruhezeit');
            if ($ruhe >= 0 && $ruhe < $minRuhe) {
                $tag->warnungen['ruhezeit'] = 'Ruhezeit nur '.Format::dauer($ruhe).' Std. statt '.Format::dauer($minRuhe).' (§ 5 ArbZG)';
            }
        }

        if (Einstellungen::bool('sonntag_warnen') && ($tag->datum->dayOfWeekIso === 7 || $tag->feiertag)) {
            $tag->warnungen['sonntag'] = $tag->feiertag
                ? "Arbeit am Feiertag ({$tag->feiertag}, § 9 ArbZG)"
                : 'Arbeit am Sonntag (§ 9 ArbZG)';
        }

        if ($tag->offen && $tag->datum->lt($this->jetzt->copy()->startOfDay())) {
            $tag->warnungen['offen'] = 'Nicht ausgestempelt';
        }
    }

    /**
     * Längste Arbeitsstrecke ohne Ruhepause: Abschnitte mit Lücken unter 15
     * Minuten gelten als zusammenhängend.
     *
     * @param  Collection<int, Buchung>  $abschnitte
     */
    public static function laengsterBlock(Collection $abschnitte, ?Carbon $jetzt = null): int
    {
        $laengster = 0;
        $start = null;
        $ende = null;

        foreach ($abschnitte as $b) {
            $bEnde = $b->ende ?? ($jetzt ?? now());
            if ($start === null || intdiv($b->beginn->getTimestamp() - $ende->getTimestamp(), 60) >= 15) {
                $start = $b->beginn;
            }
            $ende = $bEnde;
            $laengster = max($laengster, intdiv($ende->getTimestamp() - $start->getTimestamp(), 60));
        }

        return $laengster;
    }

    /**
     * @param  list<Tag>  $tage
     * @return array{soll: int, ist: int, gutschrift: int, saldo: int, pause: int}
     */
    public static function summe(array $tage): array
    {
        $s = ['soll' => 0, 'ist' => 0, 'gutschrift' => 0, 'saldo' => 0, 'pause' => 0];
        foreach ($tage as $t) {
            $s['soll'] += $t->mitModell ? $t->soll : 0;
            $s['ist'] += $t->ist();
            $s['gutschrift'] += $t->gutschrift;
            $s['saldo'] += $t->saldo();
            $s['pause'] += $t->pause;
        }

        return $s;
    }

    /**
     * Stundenkonto am Ende von $stichtag: Übertrag des Jahres + Salden aller
     * Tage vom 1.1. bis zum Stichtag. Der laufende Tag zählt erst, wenn er vorbei ist.
     */
    public static function kontostand(User $user, ?Carbon $stichtag = null): int
    {
        $stichtag ??= now()->subDay();
        $von = $stichtag->copy()->startOfYear();
        if ($stichtag->lt($von)) {
            return 0;
        }

        $uebertrag = (int) Konto::where('user_id', $user->id)->where('jahr', $von->year)->value('saldo_uebertrag');
        $rechner = new self($user, $von, $stichtag);

        return $uebertrag + self::summe($rechner->tage())['saldo'];
    }

    /**
     * Urlaubskonto eines Jahres in Tagen.
     *
     * @return array{anspruch: float, uebertrag: float, genommen: float, geplant: float, rest: float}
     */
    public static function urlaub(User $user, int $jahr): array
    {
        $von = Carbon::create($jahr, 1, 1);
        $bis = Carbon::create($jahr, 12, 31);
        $modelle = Zeitmodell::where('user_id', $user->id)->orderBy('gueltig_ab')->get();
        $modellJahr = Zeitmodell::amTag($modelle, $bis) ?? $modelle->first();

        $anspruch = (float) ($modellJahr?->urlaubstage ?? 0);
        $uebertrag = (float) Konto::where('user_id', $user->id)->where('jahr', $jahr)->value('urlaub_uebertrag');

        $zaehle = function (string $status) use ($user, $von, $bis, $modelle): float {
            $summe = 0.0;
            $liste = Abwesenheit::where('user_id', $user->id)->where('status', $status)
                ->ueberschneidet($von, $bis)->get()
                ->filter(fn (Abwesenheit $a) => $a->istUrlaub());
            foreach ($liste as $a) {
                $summe += self::arbeitstage($a, $modelle, $von, $bis);
            }

            return $summe;
        };

        $genommen = $zaehle(Abwesenheit::GENEHMIGT);
        $geplant = $zaehle(Abwesenheit::BEANTRAGT);

        return [
            'anspruch' => $anspruch,
            'uebertrag' => $uebertrag,
            'genommen' => $genommen,
            'geplant' => $geplant,
            'rest' => $anspruch + $uebertrag - $genommen,
        ];
    }

    /**
     * Wie viele Arbeitstage kostet eine Abwesenheit? Tage ohne Soll und
     * Feiertage zählen nicht; ein halber Tag zählt 0,5.
     *
     * @param  Collection<int, Zeitmodell>  $modelle
     */
    public static function arbeitstage(Abwesenheit $a, Collection $modelle, ?Carbon $grenzeVon = null, ?Carbon $grenzeBis = null): float
    {
        $von = $a->von->copy();
        $bis = $a->bis->copy();
        if ($grenzeVon && $von->lt($grenzeVon)) {
            $von = $grenzeVon->copy();
        }
        if ($grenzeBis && $bis->gt($grenzeBis)) {
            $bis = $grenzeBis->copy();
        }

        $tage = 0.0;
        for ($d = $von->copy(); $d->lte($bis); $d->addDay()) {
            $m = Zeitmodell::amTag($modelle, $d);
            // Ohne Modell: Mo–Fr als Arbeitstage annehmen.
            $arbeitstag = $m ? $m->sollFuer($d->dayOfWeekIso) > 0 : $d->dayOfWeekIso <= 5;
            $land = $m?->bundesland ?: Einstellungen::get('bundesland');
            if ($arbeitstag && ! Feiertage::name($d, $land)) {
                $tage += $a->halbtag ? 0.5 : 1.0;
            }
        }

        return $tage;
    }
}
