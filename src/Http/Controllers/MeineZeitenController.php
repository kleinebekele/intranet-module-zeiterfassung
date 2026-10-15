<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Ausweis;
use Intranet\Modules\Zeiterfassung\Models\Geraet;
use Intranet\Modules\Zeiterfassung\Models\Verstoss;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use Intranet\Modules\Zeiterfassung\Support\Stempeluhr;
use RuntimeException;

/** Die eigene Seite: Stempeluhr (Homeoffice), Monatsübersicht, Nachträge. */
class MeineZeitenController
{
    use Monatsdaten;

    public function index(Request $request)
    {
        $user = $request->user();
        $monat = $this->monat($request);

        return view('zeiterfassung::meine.index', $this->monatsdaten($user, $monat) + [
            'teilnehmer' => Rechte::istTeilnehmer($user),
            'status' => Stempeluhr::status($user),
            'webStempeln' => Rechte::darfWebStempeln($user),
            'nachtrag' => Rechte::nachtragModus($user),
            'verstoesse' => Verstoss::where('user_id', $user->id)->where('datum', '>=', now()->subDays(30))->orderByDesc('datum')->get(),
            'hatCode' => Ausweis::where('user_id', $user->id)->where('art', Ausweis::CODE)->exists(),
            'geraete' => Geraet::where('user_id', $user->id)->orderBy('name')->get(),
            'programmDa' => is_file(self::programmDatei()),
        ]);
    }

    /** Das PC-Programm herunterladen (liegt dem Modul bei, gebaut aus programm/). */
    public function programm(Request $request)
    {
        abort_unless(Rechte::darfWebStempeln($request->user()), 403, 'Stempeln am PC ist für dich nicht freigeschaltet.');
        $datei = self::programmDatei();
        abort_unless(is_file($datei), 404, 'Das Programm ist in dieser Version nicht enthalten.');

        return response()->download($datei, 'Zeiterfassung.exe', ['Content-Type' => 'application/vnd.microsoft.portable-executable']);
    }

    public static function programmDatei(): string
    {
        return dirname(__DIR__, 3).'/resources/programm/Zeiterfassung.exe';
    }

    /**
     * Version und Prüfsumme des mitgelieferten Programms (schreibt programm/bauen.ps1) – für das
     * automatische Update. Null, wenn die Angaben fehlen oder nicht zur Datei passen.
     *
     * @return array{version: string, sha256: string}|null
     */
    public static function programmInfo(): ?array
    {
        $datei = dirname(self::programmDatei()).'/version.json';
        // BOM abschneiden, falls ein Editor eins hineingeschrieben hat.
        $info = is_file($datei) ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($datei)), true) : null;
        if (! is_array($info) || empty($info['version']) || empty($info['sha256']) || ! is_file(self::programmDatei())) {
            return null;
        }

        return ['version' => (string) $info['version'], 'sha256' => strtolower((string) $info['sha256'])];
    }

    /** PC-Programm verbinden: Schlüssel wird genau einmal angezeigt. */
    public function geraetAnlegen(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::darfWebStempeln($user), 403, 'Stempeln am PC ist für dich nicht freigeschaltet.');
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);

        $token = Geraet::neuesToken();
        Geraet::create(['user_id' => $user->id, 'name' => $data['name'], 'token_hash' => Geraet::hash($token)]);
        Korrektur::audit('zeiterfassung.ausweis', "PC-Programm verbunden ({$data['name']})", $user);

        // APP_URL statt der Anfrage-Adresse: hinter dem Proxy wäre das die interne Adresse.
        return back()->with('zeit_geraet', ['name' => $data['name'], 'schluessel' => $token, 'adresse' => config('app.url') ?: url('/')]);
    }

    public function geraetEntfernen(Request $request, Geraet $geraet)
    {
        $user = $request->user();
        abort_unless($geraet->user_id === $user->id, 404);
        $geraet->delete();
        Korrektur::audit('zeiterfassung.ausweis', "PC-Programm getrennt ({$geraet->name})", $user);

        return back()->with('status', "Gerät „{$geraet->name}“ getrennt – das Programm dort kann nicht mehr stempeln.");
    }

    public function stempeln(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::darfWebStempeln($user), 403, 'Stempeln im Intranet ist für dich nicht freigeschaltet.');

        $aktion = (string) $request->input('aktion');
        abort_unless(array_key_exists($aktion, Stempeluhr::AKTIONEN), 422);

        try {
            Stempeluhr::buchen($user, $aktion, 'web');
        } catch (RuntimeException $e) {
            return $this->zurueck($e->getMessage(), true);
        }

        return back()->with('status', Stempeluhr::AKTIONEN[$aktion].' gebucht: '.now()->format('H:i').' Uhr.');
    }

    public function buchung(Request $request)
    {
        $user = $request->user();
        $modus = Rechte::nachtragModus($user);
        abort_unless($modus !== null && Rechte::istTeilnehmer($user), 403, 'Nachträge sind für dich nicht freigeschaltet.');

        try {
            [$beginn, $ende] = $this->zeitenAus($request);
            Korrektur::speichern($user, $user, $this->buchungVon($user, $request), $beginn, $ende,
                $request->input('notiz'), $modus === 'frei', 'nachtrag');
        } catch (RuntimeException $e) {
            return $this->zurueck($e->getMessage(), true);
        }

        return back()->with('status', $modus === 'frei' ? 'Gespeichert.' : 'Antrag gestellt – die Leitung muss ihn noch freigeben.');
    }

    public function loeschen(Request $request)
    {
        $user = $request->user();
        $modus = Rechte::nachtragModus($user);
        abort_unless($modus !== null, 403, 'Nachträge sind für dich nicht freigeschaltet.');

        try {
            $alt = $this->buchungVon($user, $request) ?? throw new RuntimeException('Buchung nicht gefunden.');
            Korrektur::loeschen($user, $user, $alt, $request->input('notiz'), $modus === 'frei');
        } catch (RuntimeException $e) {
            return $this->zurueck($e->getMessage(), true);
        }

        return back()->with('status', $modus === 'frei' ? 'Buchung gelöscht.' : 'Löschung beantragt.');
    }

    /** Neuer persönlicher Terminal-Code – wird genau einmal angezeigt. */
    public function code(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::istTeilnehmer($user), 403);

        $code = Ausweis::neuerCode($user, Einstellungen::int('code_laenge'));
        Korrektur::audit('zeiterfassung.ausweis', 'Neuer Terminal-Code (selbst)', $user);

        return back()->with('zeit_code', $code);
    }
}
