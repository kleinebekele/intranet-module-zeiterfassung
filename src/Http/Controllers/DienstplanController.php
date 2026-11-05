<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Zeiterfassung\Models\Abteilung;
use Intranet\Modules\Zeiterfassung\Models\Dienstplan;
use Intranet\Modules\Zeiterfassung\Models\FreierTag;
use Intranet\Modules\Zeiterfassung\Models\Schicht;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Planer;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/**
 * Dienstpläne je Abteilung und Monat: Vorschlag erzeugen, von Hand ändern,
 * veröffentlichen. Mitglieder sehen nur veröffentlichte Pläne ihrer Abteilungen.
 */
class DienstplanController
{
    use Monatsdaten;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::darfDienstplan($user), 403);

        $planer = Rechte::darfPlanen($user);
        $abteilungen = Abteilung::withCount('personen')->orderBy('name')
            ->when(! $planer, fn ($q) => $q->whereIn('id', Rechte::abteilungenVon($user)))
            ->get();

        return view('zeiterfassung::dienstplan.index', [
            'abteilungen' => $abteilungen,
            'planer' => $planer,
            'monat' => $this->monat($request),
            'meine' => Schicht::with('dienstplan.abteilung')
                ->where('user_id', $user->id)
                ->whereDate('datum', '>=', today()->toDateString())
                ->whereHas('dienstplan', fn ($q) => $q->where('status', Dienstplan::VEROEFFENTLICHT))
                ->orderBy('datum')->limit(14)->get(),
        ]);
    }

    public function zeigen(Request $request, Abteilung $abteilung)
    {
        $user = $request->user();
        $planer = Rechte::darfPlanen($user);
        abort_unless($planer || Rechte::abteilungenVon($user)->contains($abteilung->id), 403);

        $monat = $this->monat($request);
        $plan = Dienstplan::fuer($abteilung, $monat);
        // Planer sehen das Raster immer (auch ohne Plan: dann von Hand eintragen), Mitglieder nur veröffentlicht.
        $sichtbar = $planer || ($plan && $plan->istVeroeffentlicht());

        $rechner = new Planer($abteilung, $monat);
        $schichten = $sichtbar && $plan ? $plan->schichten()->get() : collect();

        return view('zeiterfassung::dienstplan.zeigen', [
            'abteilung' => $abteilung,
            'monat' => $monat,
            'plan' => $plan,
            'sichtbar' => $sichtbar,
            'planer' => $planer,
            'tage' => $rechner->tage(),
            'wochen' => $rechner->wochen(),
            'personen' => $rechner->personen(),
            'schichten' => $schichten->groupBy('user_id')->map(fn ($liste) => $liste->keyBy(fn (Schicht $s) => $s->datum->toDateString())),
            'pruefung' => $sichtbar ? $rechner->pruefen($schichten) : null,
            'ohneBesetzung' => $abteilung->besetzung()->doesntExist(),
        ]);
    }

    public function vorschlagen(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $monat = $this->monat($request);
        if ($abteilung->besetzung()->doesntExist() || $abteilung->personen()->doesntExist()) {
            return back()->with('error', 'Erst Besetzung und Personen der Abteilung eintragen.');
        }

        $plan = (new Planer($abteilung, $monat))->speichernAlsVorschlag($request->user()->id);
        Korrektur::audit('zeiterfassung.dienstplan', "Dienstplan {$abteilung->name} {$monat->isoFormat('MMMM YYYY')} neu vorgeschlagen", null);

        return redirect()->route('module.zeiterfassung.dienstplan.zeigen', [$abteilung, 'monat' => $monat->format('Y-m')])
            ->with('status', $plan->schichten()->count().' Schichten vorgeschlagen. Bitte prüfen und anpassen.');
    }

    /** Eine Schicht anlegen, ändern oder (ohne Zeiten) entfernen. */
    public function schicht(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'datum' => ['required', 'date_format:Y-m-d'],
            'von' => ['nullable', 'date_format:H:i'],
            'bis' => ['nullable', 'date_format:H:i'],
            'pause' => ['nullable', 'integer', 'min:0', 'max:240'],
            'notiz' => ['nullable', 'string', 'max:200'],
            'alle' => ['nullable', 'boolean'],
        ]);
        abort_unless($abteilung->personen()->where('users.id', $data['user_id'])->exists(), 404);

        $datum = Carbon::parse($data['datum'])->startOfDay();
        $plan = Dienstplan::fuer($abteilung, $datum)
            ?? Dienstplan::create(['abteilung_id' => $abteilung->id, 'monat' => $datum->copy()->startOfMonth(), 'status' => Dienstplan::ENTWURF, 'erstellt_von' => $request->user()->id]);

        $entfernen = empty($data['von']) && empty($data['bis']);
        if (! $entfernen && (empty($data['von']) || empty($data['bis']) || $data['von'] >= $data['bis'])) {
            return back()->with('error', 'Bitte Beginn und Ende angeben (Ende nach Beginn).');
        }

        // Ein Tag oder alle gleichen Wochentage des Monats – geschlossene Tage und Abwesenheiten bleiben frei.
        $tage = [$datum];
        $uebersprungen = 0;
        if ($request->boolean('alle')) {
            $rechner = new Planer($abteilung, $datum);
            $abwesend = $rechner->personen()[(int) $data['user_id']]['abwesend'] ?? [];
            $tage = [];
            for ($d = $datum->copy()->startOfMonth(); $d->month === $datum->month; $d->addDay()) {
                if ($d->dayOfWeekIso !== $datum->dayOfWeekIso) {
                    continue;
                }
                if (! $entfernen && (! $rechner->tage()[$d->toDateString()]['offen'] || isset($abwesend[$d->toDateString()]))) {
                    $uebersprungen++;

                    continue;
                }
                $tage[] = $d->copy();
            }
        }

        $dauer = $entfernen ? 0 : Planer::minute($data['bis']) - Planer::minute($data['von']);
        foreach ($tage as $tag) {
            $vorhanden = $plan->schichten()->where('user_id', $data['user_id'])->whereDate('datum', $tag->toDateString())->first();
            if ($entfernen) {
                $vorhanden?->delete();

                continue;
            }
            $werte = [
                'von' => $data['von'],
                'bis' => $data['bis'],
                'pause' => $data['pause'] ?? Planer::pauseFuer($dauer),
                'notiz' => $data['notiz'] ?? null,
            ];
            $vorhanden
                ? $vorhanden->update($werte)
                : $plan->schichten()->create($werte + ['user_id' => $data['user_id'], 'datum' => $tag]);
        }

        $text = $entfernen ? 'Schicht entfernt' : 'Schicht gespeichert';
        if (count($tage) > 1 || $uebersprungen) {
            $text .= ' ('.count($tage).' Tage'.($uebersprungen ? ", {$uebersprungen} übersprungen: geschlossen oder abwesend" : '').')';
        }

        return back()->with('status', $text.'.');
    }

    public function status(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $plan = Dienstplan::fuer($abteilung, $this->monat($request));
        abort_unless($plan, 404);

        $veroeffentlichen = $request->input('status') === Dienstplan::VEROEFFENTLICHT;
        $plan->update([
            'status' => $veroeffentlichen ? Dienstplan::VEROEFFENTLICHT : Dienstplan::ENTWURF,
            'veroeffentlicht_am' => $veroeffentlichen ? now() : null,
        ]);
        Korrektur::audit('zeiterfassung.dienstplan', "Dienstplan {$abteilung->name} {$plan->monat->isoFormat('MMMM YYYY')} ".($veroeffentlichen ? 'veröffentlicht' : 'zurück auf Entwurf'), null);

        return back()->with('status', $veroeffentlichen ? 'Veröffentlicht – die Mitglieder sehen den Plan jetzt.' : 'Zurück auf Entwurf – für Mitglieder ausgeblendet.');
    }

    /**
     * Freie Tage einer Person setzen oder entfernen: ein Zeitraum (von–bis), ein Tag oder die
     * ganze Woche des Tages. Beim Setzen fallen Schichten dieser Abteilung an den Tagen weg.
     */
    public function frei(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'von' => ['required', 'date_format:Y-m-d'],
            'bis' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:von'],
            'woche' => ['nullable', 'boolean'],
            'notiz' => ['nullable', 'string', 'max:200'],
            'aktion' => ['required', 'in:setzen,entfernen'],
        ]);
        $person = $abteilung->personen()->where('users.id', $data['user_id'])->first();
        abort_unless($person, 404);

        $von = Carbon::parse($data['von'])->startOfDay();
        $bis = Carbon::parse($data['bis'] ?? $data['von'])->startOfDay();
        if ($request->boolean('woche')) {
            [$von, $bis] = [$von->copy()->startOfWeek(), $von->copy()->endOfWeek()->startOfDay()];
        }
        if ($von->diffInDays($bis) > 62) {
            return back()->with('error', 'Höchstens zwei Monate auf einmal.');
        }

        $zeitraum = $von->isSameDay($bis) ? $von->isoFormat('dd DD.MM.') : $von->isoFormat('DD.MM.').'–'.$bis->isoFormat('DD.MM.');
        if ($data['aktion'] === 'entfernen') {
            FreierTag::where('user_id', $person->id)->whereDate('datum', '>=', $von->toDateString())
                ->whereDate('datum', '<=', $bis->toDateString())->delete();

            return back()->with('status', "Freie Tage von {$person->name} ({$zeitraum}) entfernt.");
        }

        $weg = 0;
        DB::transaction(function () use ($data, $person, $von, $bis, $abteilung, $request, &$weg) {
            for ($d = $von->copy(); $d->lte($bis); $d->addDay()) {
                FreierTag::updateOrCreate(['user_id' => $person->id, 'datum' => $d->toDateString()],
                    ['notiz' => $data['notiz'] ?? null, 'erfasst_von' => $request->user()->id]);
            }
            $weg = Schicht::where('user_id', $person->id)
                ->whereDate('datum', '>=', $von->toDateString())->whereDate('datum', '<=', $bis->toDateString())
                ->whereHas('dienstplan', fn ($q) => $q->where('abteilung_id', $abteilung->id))
                ->delete();
        });
        Korrektur::audit('zeiterfassung.dienstplan', "Freie Tage {$person->name} {$zeitraum} eingetragen ({$abteilung->name})", $person);

        return back()->with('status', "{$person->name} hat frei: {$zeitraum}".($weg ? " – {$weg} Schicht".($weg > 1 ? 'en' : '').' entfernt' : '').'.');
    }

    /** Entwurf samt Schichten entfernen – veröffentlichte Pläne erst zurück auf Entwurf setzen. */
    public function entwurfEntfernen(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $monat = $this->monat($request);
        $plan = Dienstplan::fuer($abteilung, $monat);
        abort_unless($plan, 404);
        if ($plan->istVeroeffentlicht()) {
            return back()->with('error', 'Der Plan ist veröffentlicht – erst „Zurück auf Entwurf“, dann entfernen.');
        }

        DB::transaction(function () use ($plan) {
            $plan->schichten()->delete();
            $plan->delete();
        });
        Korrektur::audit('zeiterfassung.dienstplan', "Dienstplan-Entwurf {$abteilung->name} {$monat->isoFormat('MMMM YYYY')} entfernt", null);

        return redirect()->route('module.zeiterfassung.dienstplan.zeigen', [$abteilung, 'monat' => $monat->format('Y-m')])
            ->with('status', 'Entwurf entfernt.');
    }

    public function druck(Request $request, Abteilung $abteilung)
    {
        $user = $request->user();
        $planer = Rechte::darfPlanen($user);
        abort_unless($planer || Rechte::abteilungenVon($user)->contains($abteilung->id), 403);

        $monat = $this->monat($request);
        $plan = Dienstplan::fuer($abteilung, $monat);
        abort_unless($plan && ($planer || $plan->istVeroeffentlicht()), 404);

        $rechner = new Planer($abteilung, $monat);
        $schichten = $plan->schichten()->get();

        return view('zeiterfassung::dienstplan.druck', [
            'abteilung' => $abteilung,
            'monat' => $monat,
            'plan' => $plan,
            'tage' => $rechner->tage(),
            'wochen' => $rechner->wochen(),
            'personen' => $rechner->personen(),
            'schichten' => $schichten->groupBy('user_id')->map(fn ($liste) => $liste->keyBy(fn (Schicht $s) => $s->datum->toDateString())),
            'pruefung' => $rechner->pruefen($schichten),
        ]);
    }

    private function darf(Request $request): void
    {
        abort_unless(Rechte::darfPlanen($request->user()), 403);
    }
}
