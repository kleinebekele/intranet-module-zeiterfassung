<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Abteilung;
use Intranet\Modules\Zeiterfassung\Models\Besetzung;
use Intranet\Modules\Zeiterfassung\Models\Sondertag;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;
use Intranet\Modules\Zeiterfassung\Support\Feiertage;
use Intranet\Modules\Zeiterfassung\Support\Format;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/** Abteilungen für den Dienstplan: Stammdaten, Besetzung je Wochentag, Personen, Sondertage. */
class AbteilungController
{
    public function anlegen(Request $request)
    {
        $this->darf($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);

        $abteilung = Abteilung::create(['name' => $data['name']]);

        return redirect()->route('module.zeiterfassung.dienstplan.abteilung', $abteilung)
            ->with('status', 'Abteilung angelegt – jetzt Besetzung und Personen eintragen.');
    }

    public function bearbeiten(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $abteilung->load(['besetzung', 'personen', 'sondertage' => fn ($q) => $q->whereDate('datum', '>=', today()->subMonths(2))]);

        $modelle = Zeitmodell::whereIn('user_id', $abteilung->personen->pluck('id'))
            ->orderBy('gueltig_ab')->get()->groupBy('user_id')
            ->map(fn ($liste) => Zeitmodell::amTag($liste, today()));

        return view('zeiterfassung::dienstplan.abteilung', [
            'abteilung' => $abteilung,
            'modelle' => $modelle,
            'benutzer' => User::orderBy('name')->get(['id', 'name']),
            'laender' => Feiertage::LAENDER,
            'feiertage' => collect(Feiertage::fuerJahr(today()->year, $abteilung->land()))
                ->union(Feiertage::fuerJahr(today()->year + 1, $abteilung->land()))
                ->filter(fn ($name, $datum) => $datum >= today()->toDateString())
                ->take(14),
        ]);
    }

    public function speichern(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'bundesland' => ['nullable', 'in:'.implode(',', array_keys(Feiertage::LAENDER))],
            'min_schicht' => ['required', 'string'],
            'notiz' => ['nullable', 'string', 'max:500'],
            'aushilfen_voll' => ['required', 'boolean'],
        ]);

        $min = Format::minutenAus($data['min_schicht']);
        if (! $min) {
            return back()->withInput()->with('error', 'Kürzeste Schicht bitte als 3:00 oder 3 angeben.');
        }

        $abteilung->update(['name' => $data['name'], 'bundesland' => $data['bundesland'] ?: null, 'min_schicht' => $min, 'notiz' => $data['notiz'], 'aushilfen_voll' => (bool) $data['aushilfen_voll']]);

        return back()->with('status', 'Abteilung gespeichert.');
    }

    /** Kernarbeitszeit je Wochentag (leer = keine) – gilt für den automatischen Vorschlag. */
    public function kernzeiten(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'kern' => ['nullable', 'array'],
            'kern.*.von' => ['nullable', 'date_format:H:i'],
            'kern.*.bis' => ['nullable', 'date_format:H:i'],
        ]);

        $kern = [];
        foreach ($data['kern'] ?? [] as $tag => $k) {
            if (empty($k['von']) && empty($k['bis'])) {
                continue;
            }
            if (empty($k['von']) || empty($k['bis']) || $k['von'] >= $k['bis'] || (int) $tag < 1 || (int) $tag > 7) {
                return back()->withInput()->with('error', 'Kernarbeitszeit: bitte je Tag Beginn und Ende angeben (Ende nach Beginn) oder beides leer lassen.');
            }
            $kern[(int) $tag] = ['von' => $k['von'], 'bis' => $k['bis']];
        }

        $abteilung->update(['kernzeiten' => $kern ?: null]);

        return back()->with('status', 'Kernarbeitszeit gespeichert.');
    }

    /** Alle Zeitfenster auf einmal: vorhandene ändern/löschen, neues für mehrere Wochentage anlegen. */
    public function besetzung(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'fenster' => ['nullable', 'array'],
            'fenster.*.von' => ['required', 'date_format:H:i'],
            'fenster.*.bis' => ['required', 'date_format:H:i'],
            'fenster.*.mindestens' => ['required', 'integer', 'min:0', 'max:50'],
            'fenster.*.optional' => ['nullable', 'integer', 'min:0', 'max:50'],
            'neu.tage' => ['nullable', 'array'],
            'neu.tage.*' => ['integer', 'between:1,7'],
            'neu.von' => ['nullable', 'date_format:H:i'],
            'neu.bis' => ['nullable', 'date_format:H:i'],
            'neu.mindestens' => ['nullable', 'integer', 'min:0', 'max:50'],
            'neu.optional' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        foreach ($data['fenster'] ?? [] as $id => $f) {
            $zeile = $abteilung->besetzung()->find($id);
            if (! $zeile) {
                continue;
            }
            if ($request->boolean("fenster.{$id}.loeschen")) {
                $zeile->delete();

                continue;
            }
            if ($f['von'] >= $f['bis']) {
                return back()->withInput()->with('error', "Zeitfenster {$f['von']}–{$f['bis']}: Ende muss nach dem Beginn liegen.");
            }
            $zeile->update(['von' => $f['von'], 'bis' => $f['bis'], 'mindestens' => $f['mindestens'], 'optional' => $f['optional'] ?? 0]);
        }

        $neu = $data['neu'] ?? [];
        if (! empty($neu['tage'])) {
            if (empty($neu['von']) || empty($neu['bis']) || $neu['von'] >= $neu['bis']) {
                return back()->withInput()->with('error', 'Neues Zeitfenster: bitte Beginn und Ende angeben (Ende nach Beginn).');
            }
            foreach (array_unique($neu['tage']) as $tag) {
                Besetzung::create([
                    'abteilung_id' => $abteilung->id,
                    'wochentag' => $tag,
                    'von' => $neu['von'],
                    'bis' => $neu['bis'],
                    'mindestens' => $neu['mindestens'] ?? 1,
                    'optional' => $neu['optional'] ?? 0,
                ]);
            }
        }
        Korrektur::audit('zeiterfassung.einstellungen', 'Besetzung der Abteilung '.$abteilung->name.' geändert', null);

        return back()->with('status', 'Besetzung gespeichert.');
    }

    public function personen(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'personen' => ['nullable', 'array'],
            'personen.*.wochenstunden' => ['nullable', 'string', 'max:10'],
            'personen.*.max_monat' => ['nullable', 'string', 'max:10'],
            'personen.*.max_tage_woche' => ['nullable', 'integer', 'between:1,6'],
            'personen.*.freie_tage' => ['nullable', 'array'],
            'personen.*.freie_tage.*' => ['integer', 'between:1,7'],
            'neu' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $sync = [];
        $position = 0;
        foreach ($data['personen'] ?? [] as $uid => $p) {
            if ($request->boolean("personen.{$uid}.entfernen")) {
                continue;
            }
            $wochen = ($p['wochenstunden'] ?? '') === '' ? null : Format::minutenAus($p['wochenstunden']);
            $budget = ($p['max_monat'] ?? '') === '' ? null : Format::minutenAus($p['max_monat']);
            if (($p['wochenstunden'] ?? '') !== '' && $wochen === null || ($p['max_monat'] ?? '') !== '' && $budget === null) {
                return back()->withInput()->with('error', 'Stunden bitte als 40:00 oder 40 angeben.');
            }
            $sync[(int) $uid] = [
                'aushilfe' => $request->boolean("personen.{$uid}.aushilfe"),
                'wochenstunden' => $wochen,
                'max_monat' => $budget,
                'max_tage_woche' => $p['max_tage_woche'] ?? null,
                'freie_tage' => implode(',', array_unique($p['freie_tage'] ?? [])) ?: null,
                'position' => $position++,
            ];
        }
        if (! empty($data['neu']) && ! isset($sync[(int) $data['neu']])) {
            $sync[(int) $data['neu']] = ['aushilfe' => false, 'position' => $position];
        }

        $abteilung->personen()->sync($sync);
        Rechte::vergessen();
        Korrektur::audit('zeiterfassung.einstellungen', 'Personen der Abteilung '.$abteilung->name.' geändert', null);

        return back()->with('status', 'Personen gespeichert.');
    }

    public function sondertag(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'bis_datum' => ['nullable', 'date', 'after_or_equal:datum'],
            'bezeichnung' => ['required', 'string', 'max:150'],
            'art' => ['required', 'in:geschlossen,offen'],
            'von' => ['nullable', 'required_if:art,offen', 'date_format:H:i'],
            'bis' => ['nullable', 'required_if:art,offen', 'date_format:H:i'],
            'mindestens' => ['nullable', 'integer', 'min:0', 'max:50'],
            'optional' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);
        if ($data['art'] === 'offen' && $data['von'] >= $data['bis']) {
            return back()->withInput()->with('error', 'Ende muss nach dem Beginn liegen.');
        }

        $von = \Illuminate\Support\Carbon::parse($data['datum'])->startOfDay();
        $bis = isset($data['bis_datum']) ? \Illuminate\Support\Carbon::parse($data['bis_datum'])->startOfDay() : $von->copy();
        if ($von->diffInDays($bis) > 62) {
            return back()->withInput()->with('error', 'Höchstens zwei Monate auf einmal.');
        }

        for ($d = $von->copy(); $d->lte($bis); $d->addDay()) {
            $abteilung->sondertage()->whereDate('datum', $d->toDateString())->delete();
            Sondertag::create([
                'abteilung_id' => $abteilung->id,
                'datum' => $d->copy(),
                'bezeichnung' => $data['bezeichnung'],
                'geschlossen' => $data['art'] === 'geschlossen',
                'von' => $data['art'] === 'offen' ? $data['von'] : null,
                'bis' => $data['art'] === 'offen' ? $data['bis'] : null,
                'mindestens' => $data['mindestens'] ?? 1,
                'optional' => $data['optional'] ?? 0,
            ]);
        }

        return back()->with('status', 'Sondertag eingetragen.');
    }

    public function sondertagEntfernen(Request $request, Abteilung $abteilung, Sondertag $sondertag)
    {
        $this->darf($request);
        abort_unless((int) $sondertag->abteilung_id === $abteilung->id, 404);
        $sondertag->delete();

        return back()->with('status', 'Sondertag entfernt.');
    }

    public function entfernen(Request $request, Abteilung $abteilung)
    {
        $this->darf($request);
        $name = $abteilung->name;
        $abteilung->delete();
        Korrektur::audit('zeiterfassung.einstellungen', "Abteilung {$name} mit allen Dienstplänen entfernt", null);

        return redirect()->route('module.zeiterfassung.dienstplan.index')->with('status', "Abteilung {$name} entfernt.");
    }

    private function darf(Request $request): void
    {
        abort_unless(Rechte::darfPlanen($request->user()), 403);
    }
}
