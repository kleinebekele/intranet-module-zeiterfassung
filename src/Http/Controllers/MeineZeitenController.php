<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Ausweis;
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
        ]);
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
