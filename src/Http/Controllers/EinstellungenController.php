<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Intranet\Modules\Zeiterfassung\Models\Gruppe;
use Intranet\Modules\Zeiterfassung\Models\Terminal;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;
use Intranet\Modules\Zeiterfassung\Support\Feiertage;
use Intranet\Modules\Zeiterfassung\Support\Format;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/** Gruppen + Leitung, Terminals und die Regeln (Arbeitszeitgesetz, Bundesland). */
class EinstellungenController
{
    private const OHNE_NETZ = 'Ohne eingetragenes Netz ist das Terminal gesperrt – bitte das Netz des Standorts eintragen.';
    public function index(Request $request)
    {
        $this->darf($request);

        $gruppen = Gruppe::with(['rolle', 'leitung'])->get()->sortBy(fn (Gruppe $g) => $g->name())->values();

        return view('zeiterfassung::einstellungen.index', [
            'gruppen' => $gruppen,
            'leitungFehlt' => $gruppen->filter(fn (Gruppe $g) => $g->leitung->isEmpty())->count(),
            'mitglieder' => DB::table('user_roles')->whereIn('role_id', $gruppen->pluck('role_id'))
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
            'code_laenge' => ['required', 'integer', 'min:6', 'max:10'],
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

    /**
     * Neue Gruppe: entweder eine neue Rolle mit diesem Namen (Rolle = Gruppe) oder eine
     * vorhandene Rolle übernehmen. Danach geht es auf die Gruppenseite (Mitglieder, Leitung).
     */
    public function gruppeAnlegen(Request $request)
    {
        $this->darf($request);
        $data = $request->validate([
            'name' => ['nullable', 'required_without:role_id', 'string', 'max:255'],
            'role_id' => ['nullable', 'string', 'exists:roles,role_id'],
        ]);

        if (! empty($data['role_id'])) {
            $rolle = Role::findOrFail($data['role_id']);
        } else {
            $basis = Str::slug($data['name']) ?: 'gruppe';
            $schluessel = Str::limit($basis, 56, '');
            for ($n = 2; Role::whereKey($schluessel)->exists(); $n++) {
                $schluessel = Str::limit($basis, 56, '').'-'.$n;
            }
            $rolle = Role::create(['role_id' => $schluessel, 'name' => $data['name']]);
            Korrektur::audit('zeiterfassung.einstellungen', "Rolle „{$rolle->name}\" angelegt", null);
        }

        $gruppe = Gruppe::firstOrCreate(['role_id' => $rolle->role_id]);
        Rechte::vergessen();

        return redirect()->route('module.zeiterfassung.einstellungen.gruppe', $gruppe)
            ->with('status', 'Gruppe angelegt – jetzt Personen und Leitung eintragen.');
    }

    /** Gruppenseite: Mitglieder mit Sollzeit und Kernzeit, Leitung. */
    public function gruppe(Request $request, Gruppe $gruppe)
    {
        $this->darf($request);
        $gruppe->load(['rolle', 'leitung']);

        $mitglieder = User::whereIn('id', DB::table('user_roles')->where('role_id', $gruppe->role_id)->pluck('user_id'))
            ->orderBy('name')->get();
        $modelle = Zeitmodell::whereIn('user_id', $mitglieder->pluck('id'))->orderBy('gueltig_ab')->orderBy('id')->get()
            ->groupBy('user_id')
            // gültiges Modell von heute; gibt es erst eins ab später, dieses zeigen
            ->map(fn ($liste) => Zeitmodell::amTag($liste, today()) ?? $liste->first());

        return view('zeiterfassung::einstellungen.gruppe', [
            'gruppe' => $gruppe,
            'mitglieder' => $mitglieder,
            'modelle' => $modelle,
            'benutzer' => User::orderBy('name')->get(['id', 'name']),
            'laender' => Feiertage::LAENDER,
            'standardLand' => Einstellungen::get('bundesland'),
            'mitgliederFest' => (bool) $gruppe->rolle?->istVerwaltet(),
        ]);
    }

    public function gruppeName(Request $request, Gruppe $gruppe)
    {
        $this->darf($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $rolle = $gruppe->rolle;
        abort_unless($rolle && ! $rolle->istVerwaltet() && ! $rolle->stammtAusManifest() && ! $rolle->isSystem(), 403);

        $rolle->update(['name' => $data['name']]);

        return back()->with('status', 'Name gespeichert.');
    }

    public function gruppeMitglied(Request $request, Gruppe $gruppe)
    {
        $this->darf($request);
        abort_if((bool) $gruppe->rolle?->istVerwaltet(), 403);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        DB::table('user_roles')->insertOrIgnore(['user_id' => $data['user_id'], 'role_id' => $gruppe->role_id]);
        Rechte::vergessen();
        Korrektur::audit('zeiterfassung.einstellungen', 'Mitglied zur Gruppe '.$gruppe->name().' hinzugefügt', User::find($data['user_id']));

        return back()->with('status', 'Person hinzugefügt.');
    }

    public function gruppeMitgliedEntfernen(Request $request, Gruppe $gruppe, User $person)
    {
        $this->darf($request);
        abort_if((bool) $gruppe->rolle?->istVerwaltet(), 403);

        DB::table('user_roles')->where('user_id', $person->id)->where('role_id', $gruppe->role_id)->delete();
        Rechte::vergessen();
        Korrektur::audit('zeiterfassung.einstellungen', 'Mitglied aus der Gruppe '.$gruppe->name().' entfernt', $person);

        return back()->with('status', "{$person->name} aus der Gruppe entfernt. Erfasste Zeiten bleiben erhalten.");
    }

    public function gruppeLeitung(Request $request, Gruppe $gruppe)
    {
        $this->darf($request);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $gruppe->leitung()->syncWithoutDetaching([$data['user_id']]);
        Rechte::vergessen();
        Korrektur::audit('zeiterfassung.einstellungen', 'Leitung der Gruppe '.$gruppe->name().' ergänzt', User::find($data['user_id']));

        return back()->with('status', 'Leitung eingetragen.');
    }

    public function gruppeLeitungEntfernen(Request $request, Gruppe $gruppe, User $person)
    {
        $this->darf($request);
        if ($gruppe->leitung()->count() <= 1) {
            return back()->with('error', 'Eine Gruppe braucht mindestens eine Leitung – erst eine andere Person eintragen.');
        }

        $gruppe->leitung()->detach($person->id);
        Rechte::vergessen();
        Korrektur::audit('zeiterfassung.einstellungen', 'Leitung der Gruppe '.$gruppe->name().' entfernt', $person);

        return back()->with('status', 'Leitung entfernt.');
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
        $terminal = Terminal::create($data + ['token_hash' => Terminal::hash($token), 'aktiv' => true]);

        return back()->with('zeit_terminal_url', route('zeiterfassung.terminal.index', $token))
            ->with($terminal->hatNetze() ? [] : ['error' => self::OHNE_NETZ]);
    }

    public function terminalSpeichern(Request $request, Terminal $terminal)
    {
        $this->darf($request);
        $data = $this->terminalDaten($request);
        if (is_string($data)) {
            return back()->withInput()->with('error', $data);
        }

        $terminal->update($data + ['aktiv' => $request->boolean('aktiv')]);

        return $terminal->hatNetze()
            ? back()->with('status', 'Terminal gespeichert.')
            : back()->with('error', 'Terminal gespeichert. '.self::OHNE_NETZ);
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
