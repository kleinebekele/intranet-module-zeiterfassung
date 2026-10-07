<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Ausweis;
use Intranet\Modules\Zeiterfassung\Models\Terminal;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;
use Intranet\Modules\Zeiterfassung\Support\Format;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use Intranet\Modules\Zeiterfassung\Support\Stempeluhr;
use RuntimeException;

/**
 * Stempel-Terminal (Tablet im Kiosk-Modus): Chip auflegen oder Code tippen,
 * dann Kommen / Pause / Gehen. Die erkannte Person steht nur kurz in der
 * Session (SITZUNG Sekunden) – danach muss sie sich neu melden.
 */
class TerminalController
{
    private const SESSION = 'zeit_terminal_person';

    private const SITZUNG = 60;

    public function index(Request $request)
    {
        $terminal = $this->terminal($request);
        $schluessel = (string) $request->route('schluessel');

        return view('zeiterfassung::terminal.index', [
            'terminal' => $terminal,
            'urlErkennen' => route('zeiterfassung.terminal.erkennen', $schluessel),
            'urlBuchen' => route('zeiterfassung.terminal.buchen', $schluessel),
            'codeLaenge' => Einstellungen::int('code_laenge'),
        ]);
    }

    public function erkennen(Request $request): JsonResponse
    {
        $data = $request->validate(['eingabe' => ['required', 'string', 'max:100']]);
        $request->session()->forget(self::SESSION);

        $person = Ausweis::personFuer($data['eingabe']);
        $istCode = (bool) preg_match('/^\d{4,10}$/', trim($data['eingabe']));

        if (! $person) {
            return response()->json([
                'found' => false,
                'meldung' => $istCode ? 'Code unbekannt.' : 'Chip unbekannt.',
                // Hilft beim Einrichten: die Kennung lässt sich unter Team → Person eintragen.
                'kennung' => $istCode ? null : Ausweis::chipNormal($data['eingabe']),
            ]);
        }

        if (! Rechte::istTeilnehmer($person) || (method_exists($person, 'istGesperrt') && $person->istGesperrt())) {
            return response()->json(['found' => false, 'meldung' => 'Für die Zeiterfassung nicht freigeschaltet.']);
        }

        $request->session()->put(self::SESSION, ['id' => $person->id, 'zeit' => time()]);

        return response()->json(['found' => true] + $this->zustand($person));
    }

    public function buchen(Request $request): JsonResponse
    {
        $data = $request->validate(['aktion' => ['required', 'in:'.implode(',', array_keys(Stempeluhr::AKTIONEN))]]);

        $sitzung = $request->session()->pull(self::SESSION);
        $person = ($sitzung && time() - $sitzung['zeit'] <= self::SITZUNG) ? User::find($sitzung['id']) : null;
        if (! $person) {
            return response()->json(['ok' => false, 'meldung' => 'Bitte Chip noch einmal auflegen.'], 409);
        }

        try {
            $buchung = Stempeluhr::buchen($person, $data['aktion'], 'terminal', $this->terminal($request)->id);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'meldung' => $e->getMessage()], 409);
        }

        $zeit = ($data['aktion'] === 'kommen' || $data['aktion'] === 'weiter' ? $buchung->beginn : $buchung->ende)->format('H:i');
        $vorname = strtok($person->name, ' ');
        $text = match ($data['aktion']) {
            'kommen' => (now()->hour < 11 ? 'Guten Morgen' : 'Hallo').", {$vorname}! Gekommen um {$zeit}.",
            'pause' => "Gute Pause, {$vorname}! Pause ab {$zeit}.",
            'weiter' => "Weiter geht's, {$vorname}! Pause beendet um {$zeit}.",
            default => "Tschüss, {$vorname}! Gegangen um {$zeit}.",
        };

        return response()->json(['ok' => true, 'text' => $text] + $this->zustand($person));
    }

    /** @return array<string, mixed> */
    private function zustand(User $person): array
    {
        $s = Stempeluhr::status($person);
        $maxBlock = Einstellungen::int('max_block');

        return [
            'name' => $person->name,
            'zustand' => $s['zustand'],
            'seit' => $s['seit']?->format('H:i'),
            'heute' => Format::dauer($s['heute']),
            'aktionen' => array_map(fn ($a) => ['key' => $a, 'label' => Stempeluhr::AKTIONEN[$a]], $s['aktionen']),
            'warnung' => $s['zustand'] === 'da' && $s['block'] >= $maxBlock - 15
                ? 'Seit '.Format::dauer($s['block']).' Std. ohne Pause – bitte Pause machen.'
                : null,
        ];
    }

    private function terminal(Request $request): Terminal
    {
        return $request->attributes->get('zeit_terminal');
    }
}
