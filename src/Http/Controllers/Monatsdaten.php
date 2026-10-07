<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use Intranet\Modules\Zeiterfassung\Support\Rechner;
use RuntimeException;

/** Gemeinsames für „Meine Zeiten" und die Personenseite im Team. */
trait Monatsdaten
{
    protected function monat(Request $request): Carbon
    {
        $monat = (string) $request->query('monat', '');

        return preg_match('/^\d{4}-\d{2}$/', $monat)
            ? Carbon::createFromFormat('Y-m-d', $monat.'-01')->startOfDay()
            : now()->startOfMonth();
    }

    /** @return array<string, mixed> */
    protected function monatsdaten(User $person, Carbon $monat): array
    {
        $bis = $monat->copy()->endOfMonth()->startOfDay();
        $rechner = new Rechner($person, $monat, $bis);
        $tage = $rechner->tage();

        // Wochen-Höchstarbeitszeit (48 Std.) je Kalenderwoche, soweit im Monat sichtbar.
        $wochen = [];
        foreach ($tage as $t) {
            $kw = $t->datum->isoFormat('GGGG-WW');
            $wochen[$kw] = ($wochen[$kw] ?? 0) + $t->ist();
        }

        $antraege = Buchung::where('user_id', $person->id)->where('status', Buchung::BEANTRAGT)
            ->zwischen($monat, $bis)->orderBy('beginn')->get();

        return [
            'person' => $person,
            'monat' => $monat,
            'tage' => $tage,
            'summe' => Rechner::summe($tage),
            'wochen' => $wochen,
            'antraege' => $antraege,
            'kontostand' => Rechner::kontostand($person),
            'urlaub' => Rechner::urlaub($person, $monat->year),
            'hatModell' => $rechner->modelle->isNotEmpty(),
        ];
    }

    /**
     * Datum + Uhrzeiten aus dem Buchungsformular. Liegt das Ende vor dem
     * Beginn, ist es der Folgetag (Nachtschicht).
     *
     * @return array{0: Carbon, 1: ?Carbon}
     */
    protected function zeitenAus(Request $request, bool $endeOptional = false): array
    {
        $data = $request->validate([
            'datum' => ['required', 'date_format:Y-m-d'],
            'von' => ['required', 'date_format:H:i'],
            'bis' => [$endeOptional ? 'nullable' : 'required', 'date_format:H:i'],
            'notiz' => ['nullable', 'string', 'max:500'],
        ]);

        $beginn = Carbon::createFromFormat('Y-m-d H:i', $data['datum'].' '.$data['von'])->startOfMinute();
        $ende = null;
        if (! empty($data['bis'])) {
            $ende = Carbon::createFromFormat('Y-m-d H:i', $data['datum'].' '.$data['bis'])->startOfMinute();
            if ($ende->lte($beginn)) {
                $ende->addDay();
            }
        }

        return [$beginn, $ende];
    }

    protected function buchungVon(User $person, Request $request): ?Buchung
    {
        $id = $request->integer('buchung_id');
        if (! $id) {
            return null;
        }

        $b = Buchung::where('user_id', $person->id)->find($id);
        if (! $b) {
            throw new RuntimeException('Buchung nicht gefunden.');
        }

        return $b;
    }

    protected function zurueck(string $meldung, bool $fehler = false)
    {
        return back()->withInput()->with($fehler ? 'error' : 'status', $meldung);
    }
}
