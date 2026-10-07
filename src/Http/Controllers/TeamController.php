<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Ausweis;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use Intranet\Modules\Zeiterfassung\Models\Konto;
use Intranet\Modules\Zeiterfassung\Models\Verstoss;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;
use Intranet\Modules\Zeiterfassung\Support\Feiertage;
use Intranet\Modules\Zeiterfassung\Support\Format;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechner;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use Intranet\Modules\Zeiterfassung\Support\Stempeluhr;
use RuntimeException;

/**
 * Team: wer ist da, Salden, Verstöße – und die Personenseite, auf der die
 * Leitung Zeiten korrigiert, Sollzeiten, Konten, Chips und Abwesenheiten pflegt.
 * Alles, was die Leitung hier tut, gilt sofort („Chef darf immer ändern").
 */
class TeamController
{
    use Monatsdaten;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::darfTeam($user), 403);

        $ids = Rechte::verwalteteIds($user);
        $personen = User::whereIn('id', $ids)->orderBy('name')->get();

        $heute = today();
        $abwesend = Abwesenheit::genehmigt()->whereIn('user_id', $ids)->ueberschneidet($heute, $heute)->get()->keyBy('user_id');
        $antraege = Buchung::where('status', Buchung::BEANTRAGT)->whereIn('user_id', $ids)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $antraegeAbw = Abwesenheit::where('status', Abwesenheit::BEANTRAGT)->whereIn('user_id', $ids)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $verstoesse = Verstoss::whereNull('gesehen_am')->whereIn('user_id', $ids)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $ohneModell = $ids->diff(Zeitmodell::whereIn('user_id', $ids)->distinct()->pluck('user_id'));

        $zeilen = $personen->map(fn (User $p) => [
            'person' => $p,
            'status' => Stempeluhr::status($p),
            'abwesenheit' => $abwesend->get($p->id),
            'konto' => $ohneModell->contains($p->id) ? null : Rechner::kontostand($p),
            'antraege' => ($antraege[$p->id] ?? 0) + ($antraegeAbw[$p->id] ?? 0),
            'verstoesse' => $verstoesse[$p->id] ?? 0,
        ]);

        return view('zeiterfassung::team.index', [
            'zeilen' => $zeilen,
            'istVerwaltung' => Rechte::istVerwaltung($user),
            'gruppen' => Rechte::istVerwaltung($user) ? null : Rechte::geleiteteGruppen($user),
        ]);
    }

    public function person(Request $request, User $person)
    {
        $this->darf($request, $person);
        $monat = $this->monat($request);

        return view('zeiterfassung::team.person', $this->monatsdaten($person, $monat) + [
            'status' => Stempeluhr::status($person),
            'modelle' => Zeitmodell::where('user_id', $person->id)->orderByDesc('gueltig_ab')->get(),
            'konto' => Konto::firstOrNew(['user_id' => $person->id, 'jahr' => $monat->year]),
            'ausweise' => Ausweis::where('user_id', $person->id)->orderBy('art')->get(),
            'abwesenheiten' => Abwesenheit::where('user_id', $person->id)
                ->where('bis', '>=', now()->subMonths(3)->toDateString())->orderByDesc('von')->get(),
            'verstoesse' => Verstoss::where('user_id', $person->id)->whereNull('gesehen_am')->orderByDesc('datum')->get(),
            'laender' => Feiertage::LAENDER,
            'standardLand' => Einstellungen::get('bundesland'),
        ]);
    }

    public function buchung(Request $request, User $person)
    {
        $this->darf($request, $person);

        try {
            [$beginn, $ende] = $this->zeitenAus($request, true);
            Korrektur::speichern($person, $request->user(), $this->buchungVon($person, $request), $beginn, $ende,
                $request->input('notiz'), true, 'leitung');
        } catch (RuntimeException $e) {
            return $this->zurueck($e->getMessage(), true);
        }

        return back()->with('status', 'Gespeichert.');
    }

    public function loeschen(Request $request, User $person)
    {
        $this->darf($request, $person);

        try {
            $alt = $this->buchungVon($person, $request) ?? throw new RuntimeException('Buchung nicht gefunden.');
            Korrektur::loeschen($person, $request->user(), $alt, $request->input('notiz'), true);
        } catch (RuntimeException $e) {
            return $this->zurueck($e->getMessage(), true);
        }

        return back()->with('status', 'Buchung gelöscht.');
    }

    /** Für jemanden stempeln, der es vergessen hat (z. B. Gehen nachholen). */
    public function stempeln(Request $request, User $person)
    {
        $this->darf($request, $person);
        $aktion = (string) $request->input('aktion');
        abort_unless(array_key_exists($aktion, Stempeluhr::AKTIONEN), 422);

        try {
            Stempeluhr::buchen($person, $aktion, 'leitung');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', Stempeluhr::AKTIONEN[$aktion].' für '.$person->name.' gebucht.');
    }

    public function modell(Request $request, User $person)
    {
        $this->darf($request, $person);

        $data = $request->validate([
            'gueltig_ab' => ['required', 'date_format:Y-m-d'],
            'soll' => ['required', 'array'],
            'soll.*' => ['nullable', 'string', 'max:6'],
            'urlaubstage' => ['required', 'numeric', 'min:0', 'max:99'],
            'bundesland' => ['nullable', 'in:'.implode(',', array_keys(Feiertage::LAENDER))],
            'notiz' => ['nullable', 'string', 'max:500'],
        ]);

        $werte = [];
        foreach (array_keys(Zeitmodell::TAGE) as $t) {
            $min = Format::minutenAus($data['soll'][$t] ?? '');
            if ($min === null || $min > 24 * 60) {
                return back()->withInput()->with('error', 'Sollzeit für '.Zeitmodell::TAGE[$t].' bitte als 7:30 oder 7,5 angeben.');
            }
            $werte["soll_{$t}"] = $min;
        }

        // whereDate: das Datum liegt je nach Datenbank mit Uhrzeit 00:00:00 vor.
        $modell = Zeitmodell::where('user_id', $person->id)->whereDate('gueltig_ab', $data['gueltig_ab'])->first()
            ?? new Zeitmodell(['user_id' => $person->id, 'gueltig_ab' => $data['gueltig_ab']]);
        $modell->fill(
            $werte + [
                'urlaubstage' => $data['urlaubstage'],
                'bundesland' => $data['bundesland'] ?: null,
                'notiz' => $data['notiz'] ?? null,
                'erfasst_von' => $request->user()->id,
            ],
        )->save();
        Korrektur::audit('zeiterfassung.modell', 'Sollzeit ab '.Carbon::parse($data['gueltig_ab'])->format('d.m.Y'), $person);

        return back()->with('status', 'Sollzeit gespeichert.');
    }

    public function modellEntfernen(Request $request, User $person, Zeitmodell $modell)
    {
        $this->darf($request, $person);
        abort_unless($modell->user_id === $person->id, 404);

        $modell->delete();
        Korrektur::audit('zeiterfassung.modell', 'Sollzeit ab '.$modell->gueltig_ab->format('d.m.Y').' entfernt', $person);

        return back()->with('status', 'Sollzeit entfernt.');
    }

    public function konto(Request $request, User $person)
    {
        $this->darf($request, $person);

        $data = $request->validate([
            'jahr' => ['required', 'integer', 'min:2000', 'max:2100'],
            'urlaub_uebertrag' => ['required', 'numeric', 'min:-99', 'max:99'],
            'saldo_uebertrag' => ['nullable', 'string', 'max:8'],
        ]);

        $text = trim((string) ($data['saldo_uebertrag'] ?? ''));
        $minus = str_starts_with($text, '-') || str_starts_with($text, '−');
        $min = Format::minutenAus(ltrim($text, '-−+ '));
        if ($min === null) {
            return back()->withInput()->with('error', 'Stundenübertrag bitte als 12:30 oder -4,5 angeben.');
        }

        Konto::updateOrCreate(['user_id' => $person->id, 'jahr' => $data['jahr']], [
            'urlaub_uebertrag' => $data['urlaub_uebertrag'],
            'saldo_uebertrag' => $minus ? -$min : $min,
        ]);
        Korrektur::audit('zeiterfassung.modell', "Übertrag {$data['jahr']} gesetzt", $person);

        return back()->with('status', 'Übertrag gespeichert.');
    }

    public function abwesenheit(Request $request, User $person)
    {
        $this->darf($request, $person);

        $data = AbwesenheitController::pruefen($request);
        if ($fehler = AbwesenheitController::ueberschneidung($person, $data)) {
            return back()->withInput()->with('error', $fehler);
        }

        $a = Abwesenheit::create($data + [
            'user_id' => $person->id,
            'status' => Abwesenheit::GENEHMIGT,
            'erfasst_von' => $request->user()->id,
            'entschieden_von' => $request->user()->id,
            'entschieden_am' => now(),
        ]);
        AbwesenheitController::auditEintrag($a);

        return back()->with('status', 'Abwesenheit eingetragen.');
    }

    public function abwesenheitStornieren(Request $request, User $person, Abwesenheit $abwesenheit)
    {
        $this->darf($request, $person);
        abort_unless($abwesenheit->user_id === $person->id, 404);

        $abwesenheit->update(['status' => Abwesenheit::STORNIERT]);

        return back()->with('status', 'Abwesenheit storniert.');
    }

    public function chip(Request $request, User $person)
    {
        $this->darf($request, $person);
        $data = $request->validate([
            'kennung' => ['required', 'string', 'max:100'],
            'bezeichnung' => ['nullable', 'string', 'max:150'],
        ]);

        $wert = Ausweis::chipNormal($data['kennung']);
        if (strlen($wert) < 4) {
            return back()->withInput()->with('error', 'Die Chip-Kennung ist zu kurz.');
        }
        $vorhanden = Ausweis::where('art', Ausweis::CHIP)->where('wert', $wert)->first();
        if ($vorhanden) {
            return back()->withInput()->with('error', 'Dieser Chip ist schon '.($vorhanden->user_id === $person->id ? 'eingetragen.' : 'jemand anderem zugeordnet.'));
        }

        Ausweis::create(['user_id' => $person->id, 'art' => Ausweis::CHIP, 'wert' => $wert, 'bezeichnung' => $data['bezeichnung'] ?? null]);
        Korrektur::audit('zeiterfassung.ausweis', 'Chip zugeordnet', $person);

        return back()->with('status', 'Chip zugeordnet.');
    }

    public function code(Request $request, User $person)
    {
        $this->darf($request, $person);
        $wunsch = trim((string) $request->input('code', ''));
        if ($wunsch !== '' && ! preg_match('/^\d{4,10}$/', $wunsch)) {
            return back()->with('error', 'Ein Code besteht aus 4 bis 10 Ziffern.');
        }

        try {
            $code = Ausweis::neuerCode($person, Einstellungen::int('code_laenge'), $wunsch !== '' ? $wunsch : null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        Korrektur::audit('zeiterfassung.ausweis', 'Terminal-Code neu gesetzt', $person);

        return back()->with('zeit_code', $code);
    }

    public function ausweisEntfernen(Request $request, User $person, Ausweis $ausweis)
    {
        $this->darf($request, $person);
        abort_unless($ausweis->user_id === $person->id, 404);

        $ausweis->delete();
        Korrektur::audit('zeiterfassung.ausweis', ($ausweis->art === Ausweis::CODE ? 'Code' : 'Chip').' entfernt', $person);

        return back()->with('status', 'Entfernt.');
    }

    public function verstoesseGesehen(Request $request, User $person)
    {
        $this->darf($request, $person);

        Verstoss::where('user_id', $person->id)->whereNull('gesehen_am')
            ->update(['gesehen_von' => $request->user()->id, 'gesehen_am' => now()]);

        return back()->with('status', 'Als gesehen markiert.');
    }

    private function darf(Request $request, User $person): void
    {
        abort_unless(Rechte::darfVerwalten($request->user(), $person->id), 403, 'Diese Person gehört nicht zu deinen Gruppen.');
    }
}
