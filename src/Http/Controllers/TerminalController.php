<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

    private const CODE_FEHLER = 'zeit-terminal-code-fehler:';

    private const CODE_SPERRE = 'zeit-terminal-code-sperre:';

    private const FEHLVERSUCHE = 10;

    private const FENSTER = 300;

    private const SPERRZEIT = 300;

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

        $terminal = $this->terminal($request);
        $istCode = Ausweis::istCode($data['eingabe']);
        $sperre = self::CODE_SPERRE.$terminal->id;

        // Code-Eingang dieses Terminals gesperrt: Chips (auch rein numerische) gehen weiter.
        $gesperrt = $istCode && RateLimiter::tooManyAttempts($sperre, 1);
        $person = Ausweis::personFuer($data['eingabe'], codes: ! $gesperrt);
        $freigeschaltet = $person && Rechte::istTeilnehmer($person) && ! (method_exists($person, 'istGesperrt') && $person->istGesperrt());

        if ($istCode && ! $freigeschaltet) {
            if ($gesperrt) {
                return response()->json(['found' => false, 'meldung' => $this->sperrText($sperre)]);
            }
            // Ein fremder Code und ein Code ohne Teilnahme sehen von außen gleich aus.
            if ($this->fehlversuch($terminal)) {
                return response()->json(['found' => false, 'meldung' => $this->sperrText($sperre)]);
            }

            return response()->json(['found' => false, 'meldung' => 'Code nicht erkannt.']);
        }

        if (! $person) {
            return response()->json([
                'found' => false,
                'meldung' => 'Chip unbekannt.',
                // Hilft beim Einrichten: die Kennung lässt sich unter Team → Person eintragen.
                'kennung' => Ausweis::chipNormal($data['eingabe']),
            ]);
        }

        if (! $freigeschaltet) {
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

    /**
     * Falscher Code an diesem Terminal. Nach FEHLVERSUCHE Fehlern innerhalb von
     * FENSTER Sekunden wird der Code-Eingang SPERRZEIT Sekunden gesperrt –
     * zusätzlich zur Drossel je IP, damit wechselnde Adressen nichts nützen.
     *
     * @return bool ob der Code-Eingang jetzt gesperrt ist
     */
    private function fehlversuch(Terminal $terminal): bool
    {
        $zaehler = self::CODE_FEHLER.$terminal->id;
        RateLimiter::hit($zaehler, self::FENSTER);
        if (RateLimiter::attempts($zaehler) < self::FEHLVERSUCHE) {
            return false;
        }

        RateLimiter::clear($zaehler);
        RateLimiter::hit(self::CODE_SPERRE.$terminal->id, self::SPERRZEIT);

        return true;
    }

    private function sperrText(string $sperre): string
    {
        $minuten = max(1, (int) ceil(RateLimiter::availableIn($sperre) / 60));

        return "Zu viele falsche Codes – Code-Eingabe für {$minuten} Min. gesperrt. Chip auflegen geht weiter.";
    }

    private function terminal(Request $request): Terminal
    {
        return $request->attributes->get('zeit_terminal');
    }
}
