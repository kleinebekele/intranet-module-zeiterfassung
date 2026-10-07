<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Gruppe;
use Intranet\Modules\Zeiterfassung\Models\Terminal;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;
use Intranet\Modules\Zeiterfassung\Support\Feiertage;
use Intranet\Modules\Zeiterfassung\Support\Format;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/** Gruppen + Leitung, Terminals und die Regeln (Arbeitszeitgesetz, Bundesland). */
class EinstellungenController
{
    public function index(Request $request)
    {
        $this->darf($request);

        $gruppen = Gruppe::with(['rolle', 'leitung'])->get()->sortBy(fn (Gruppe $g) => $g->name())->values();

        return view('zeiterfassung::einstellungen.index', [
            'gruppen' => $gruppen,
            'mitglieder' => \Illuminate\Support\Facades\DB::table('user_roles')->whereIn('role_id', $gruppen->pluck('role_id'))
                ->selectRaw('role_id, count(*) as n')->groupBy('role_id')->pluck('n', 'role_id'),
            'rollen' => Role::aktiv()->whereNotIn('role_id', $gruppen->pluck('role_id'))->orderBy('name')->get(),
            'benutzer' => User::orderBy('name')->get(['id', 'name']),
            'terminals' => Terminal::orderBy('name')->get(),
            'laender' => Feiertage::LAENDER,
            'werte' => array_combine(array_keys(Einstellungen::VORGABEN), array_map(fn ($k) => Einstellungen::get($k), array_keys(Einstellungen::VORGABEN))),
        ]);
    }

    public function regeln(Request $request)
    {
        $this->darf($request);
        $data = $request->validate([
            'bundesland' => ['required', 'in:'.implode(',', array_keys(Feiertage::LAENDER))],
            'max_tag' => ['required', 'string'],
            'max_block' => ['required', 'string'],
            'ruhezeit' => ['required', 'string'],
            'max_woche' => ['required', 'string'],
            'code_laenge' => ['required', 'integer', 'min:4', 'max:10'],
        ]);

        foreach (['max_tag', 'max_block', 'ruhezeit', 'max_woche'] as $k) {
            $min = Format::minutenAus($data[$k]);
            if (! $min) {
                return back()->withInput()->with('error', 'Zeiten bitte als 10:00 oder 10 angeben.');
            }
            Einstellungen::set($k, (string) $min);
        }
        Einstellungen::set('bundesland', $data['bundesland']);
        Einstellungen::set('code_laenge', (string) $data['code_laenge']);
        Einstellungen::set('pause_abziehen', $request->boolean('pause_abziehen') ? '1' : '0');
        Einstellungen::set('sonntag_warnen', $request->boolean('sonntag_warnen') ? '1' : '0');
        Korrektur::audit('zeiterfassung.einstellungen', 'Regeln geändert', null);

        return back()->with('status', 'Regeln gespeichert.');
    }

    public function gruppeAnlegen(Request $request)
    {
        $this->darf($request);
        $data = $request->validate(['role_id' => ['required', 'string', 'exists:roles,role_id']]);

        Gruppe::firstOrCreate(['role_id' => $data['role_id']]);
        Rechte::vergessen();

        return back()->with('status', 'Gruppe angelegt – jetzt die Leitung eintragen.');
    }

    public function gruppeLeitung(Request $request, Gruppe $gruppe)
    {
        $this->darf($request);
        $data = $request->validate(['leitung' => ['nullable', 'array'], 'leitung.*' => ['integer', 'exists:users,id']]);

        $gruppe->leitung()->sync($data['leitung'] ?? []);
        Korrektur::audit('zeiterfassung.einstellungen', 'Leitung der Gruppe '.$gruppe->name().' geändert', null);

        return back()->with('status', 'Leitung gespeichert.');
    }

    public function gruppeEntfernen(Request $request, Gruppe $gruppe)
    {
        $this->darf($request);
        $gruppe->delete();

        return back()->with('status', 'Gruppe entfernt. Die erfassten Zeiten bleiben erhalten.');
    }

    public function terminalAnlegen(Request $request)
    {
        $this->darf($request);
        $data = $this->terminalDaten($request);
        if (is_string($data)) {
            return back()->withInput()->with('error', $data);
        }

        $token = Terminal::neuesToken();
        Terminal::create($data + ['token_hash' => Terminal::hash($token), 'aktiv' => true]);

        return back()->with('zeit_terminal_url', route('zeiterfassung.terminal.index', $token));
    }

    public function terminalSpeichern(Request $request, Terminal $terminal)
    {
        $this->darf($request);
        $data = $this->terminalDaten($request);
        if (is_string($data)) {
            return back()->withInput()->with('error', $data);
        }

        $terminal->update($data + ['aktiv' => $request->boolean('aktiv')]);

        return back()->with('status', 'Terminal gespeichert.');
    }

    /** Neuer Schlüssel: die alte Adresse funktioniert ab sofort nicht mehr. */
    public function terminalSchluessel(Request $request, Terminal $terminal)
    {
        $this->darf($request);
        $token = Terminal::neuesToken();
        $terminal->update(['token_hash' => Terminal::hash($token)]);

        return back()->with('zeit_terminal_url', route('zeiterfassung.terminal.index', $token));
    }

    public function terminalEntfernen(Request $request, Terminal $terminal)
    {
        $this->darf($request);
        $terminal->delete();

        return back()->with('status', 'Terminal entfernt.');
    }

    /** @return array<string, mixed>|string */
    private function terminalDaten(Request $request): array|string
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'netze' => ['nullable', 'string', 'max:2000'],
        ]);

        $probe = new Terminal(['netze' => $data['netze'] ?? '']);
        foreach ($probe->netzListe() as $netz) {
            [$adresse, $maske] = array_pad(explode('/', $netz, 2), 2, null);
            $ok = filter_var($adresse, FILTER_VALIDATE_IP) && ($maske === null || (ctype_digit($maske) && (int) $maske <= (str_contains($adresse, ':') ? 128 : 32)));
            if (! $ok) {
                return "Kein gültiges Netz: {$netz}";
            }
        }

        return ['name' => $data['name'], 'netze' => $data['netze'] ?? null];
    }

    private function darf(Request $request): void
    {
        abort_unless(Rechte::istVerwaltung($request->user()), 403);
    }
}
