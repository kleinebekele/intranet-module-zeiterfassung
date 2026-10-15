<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Models\Dienstplan;
use Intranet\Modules\Zeiterfassung\Models\Schicht;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use Intranet\Modules\Zeiterfassung\Support\Stempeluhr;
use RuntimeException;

/**
 * Schnittstelle für das PC-Programm. Es misst nichts: Gebucht wird nur, was die
 * Person im Programm anklickt. Das Programm fragt den Status ab und erinnert
 * anhand von Kernzeit, Dienstplan und Arbeitszeitgesetz.
 */
class AppController
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $jetzt = now();
        $status = Stempeluhr::status($user, $jetzt);
        $offen = Stempeluhr::offen($user);

        $modelle = Zeitmodell::where('user_id', $user->id)->orderBy('gueltig_ab')->orderBy('id')->get();
        $modell = Zeitmodell::amTag($modelle, $jetzt);
        $kern = $modell?->kernFuer($jetzt->dayOfWeekIso);

        $schicht = Schicht::where('user_id', $user->id)->whereDate('datum', $jetzt->toDateString())
            ->whereHas('dienstplan', fn ($q) => $q->where('status', Dienstplan::VEROEFFENTLICHT))
            ->orderBy('von')->first();

        return response()->json([
            'ok' => true,
            'person' => $user->name,
            'zustand' => $status['zustand'],
            'seit' => $status['seit']?->toIso8601String(),
            'aktionen' => $status['aktionen'],
            'heute_minuten' => $status['heute'],
            'block_minuten' => $status['block'],
            // Offen seit einem früheren Tag: vergessen auszustempeln oder Gerät aus.
            'offen_seit_vortag' => $offen !== null && $offen->beginn->lt($jetzt->copy()->startOfDay()),
            'offen_beginn' => $offen?->beginn->toIso8601String(),
            'soll_heute_minuten' => $modell?->sollFuer($jetzt->dayOfWeekIso) ?? 0,
            'kern' => $kern ? ['von' => sprintf('%02d:%02d', intdiv($kern[0], 60), $kern[0] % 60), 'bis' => sprintf('%02d:%02d', intdiv($kern[1], 60), $kern[1] % 60)] : null,
            'schicht' => $schicht ? ['von' => $schicht->von, 'bis' => $schicht->bis, 'pause' => (int) $schicht->pause] : null,
            'regeln' => [
                'max_block' => Einstellungen::int('max_block'),
                'max_tag' => Einstellungen::int('max_tag'),
            ],
            'nachtrag' => Rechte::nachtragModus($user), // frei | antrag | null
            'server_zeit' => $jetzt->toIso8601String(),
        ]);
    }

    public function stempeln(Request $request): JsonResponse
    {
        $aktion = (string) $request->input('aktion');
        if (! array_key_exists($aktion, Stempeluhr::AKTIONEN)) {
            return response()->json(['ok' => false, 'meldung' => 'Unbekannte Aktion.'], 422);
        }

        try {
            Stempeluhr::buchen($request->user(), $aktion, 'app');
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'meldung' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'meldung' => Stempeluhr::AKTIONEN[$aktion].' gebucht: '.now()->format('H:i').' Uhr.']);
    }

    /**
     * Gehen nachtragen, wenn der Rechner aus war, während man eingestempelt war.
     * laut_geraet = die Zeit stammt aus dem Protokoll des Programms (letztes Lebenszeichen)
     * und wurde von der Person bestätigt – das gilt sofort. Eine selbst geänderte Zeit nur
     * mit Nachtragsrecht „sofort gültig"; ein Antrag ginge hier nicht, weil der Abschnitt
     * bis zur Freigabe offen bliebe und man am nächsten Tag nicht einstempeln könnte.
     */
    public function gehenNachtragen(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'zeit' => ['required', 'date'],
            'laut_geraet' => ['nullable', 'boolean'],
            'notiz' => ['nullable', 'string', 'max:300'],
        ]);

        $offen = Stempeluhr::offen($user);
        if (! $offen) {
            return response()->json(['ok' => false, 'meldung' => 'Du bist nicht eingestempelt.'], 409);
        }

        $ende = Carbon::parse($data['zeit'])->setTimezone(config('app.timezone'))->startOfMinute();
        $lautGeraet = (bool) ($data['laut_geraet'] ?? false);
        if (! $lautGeraet && Rechte::nachtragModus($user) !== 'frei') {
            return response()->json(['ok' => false, 'meldung' => 'Eine andere Zeit kannst du hier nicht eintragen. Übernimm die Zeit laut Gerät und beantrage die Korrektur unter „Meine Zeiten“ bzw. bei der Leitung.'], 403);
        }

        $notiz = $data['notiz'] ?? ($lautGeraet ? 'Rechner war aus – Ende laut PC-Programm, bestätigt' : 'Ende im PC-Programm korrigiert');

        try {
            Korrektur::speichern($user, $user, $offen, $offen->beginn, $ende, $notiz, true, 'app');
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'meldung' => $e->getMessage()], 409);
        }

        return response()->json(['ok' => true, 'meldung' => 'Gehen um '.$ende->format('H:i').' Uhr eingetragen.']);
    }
}
