<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Zeitmodell;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechner;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/** Urlaub beantragen, Krankheit melden, Übersicht und Teamkalender. */
class AbwesenheitController
{
    public function index(Request $request)
    {
        $user = $request->user();
        $jahr = (int) $request->query('jahr', now()->year);

        return view('zeiterfassung::abwesenheiten.index', [
            'jahr' => $jahr,
            'teilnehmer' => Rechte::istTeilnehmer($user),
            'urlaub' => Rechner::urlaub($user, $jahr),
            'liste' => Abwesenheit::where('user_id', $user->id)
                ->ueberschneidet(Carbon::create($jahr, 1, 1), Carbon::create($jahr, 12, 31))
                ->orderByDesc('von')->get(),
            'modelle' => Zeitmodell::where('user_id', $user->id)->orderBy('gueltig_ab')->get(),
        ]);
    }

    public function beantragen(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::istTeilnehmer($user), 403);

        $data = self::pruefen($request);
        if ($fehler = self::ueberschneidung($user, $data)) {
            return back()->withInput()->with('error', $fehler);
        }

        // Krankmeldungen brauchen keine Genehmigung; die Verwaltung trägt alles direkt ein.
        $direkt = ! Abwesenheit::brauchtGenehmigung($data['art']) || Rechte::istVerwaltung($user);

        Abwesenheit::create($data + [
            'user_id' => $user->id,
            'status' => $direkt ? Abwesenheit::GENEHMIGT : Abwesenheit::BEANTRAGT,
            'erfasst_von' => $user->id,
            'entschieden_von' => $direkt ? $user->id : null,
            'entschieden_am' => $direkt ? now() : null,
        ]);

        return back()->with('status', $direkt ? 'Eingetragen.' : 'Antrag gestellt – die Leitung muss ihn noch genehmigen.');
    }

    public function stornieren(Request $request, Abwesenheit $abwesenheit)
    {
        $user = $request->user();
        abort_unless($abwesenheit->user_id === $user->id, 403);

        // Genehmigte Abwesenheiten in der Vergangenheit ändert nur die Leitung.
        $erlaubt = $abwesenheit->status === Abwesenheit::BEANTRAGT
            || ($abwesenheit->status === Abwesenheit::GENEHMIGT && $abwesenheit->von->isFuture())
            || Rechte::istVerwaltung($user);
        if (! $erlaubt) {
            return back()->with('error', 'Bereits begonnene Abwesenheiten kann nur die Leitung ändern.');
        }

        $abwesenheit->update(['status' => Abwesenheit::STORNIERT]);

        return back()->with('status', 'Storniert.');
    }

    /** Wer ist wann weg – für die eigene Gruppe bzw. die verwalteten Personen. */
    public function kalender(Request $request)
    {
        $user = $request->user();
        $monat = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('monat'))
            ? Carbon::createFromFormat('Y-m-d', $request->query('monat').'-01')->startOfDay()
            : now()->startOfMonth();
        $bis = $monat->copy()->endOfMonth();

        $ids = Rechte::darfTeam($user) ? Rechte::verwalteteIds($user)->push($user->id) : collect([$user->id]);
        $personen = User::whereIn('id', $ids->unique())->orderBy('name')->get();
        $liste = Abwesenheit::whereIn('user_id', $ids)
            ->whereIn('status', [Abwesenheit::GENEHMIGT, Abwesenheit::BEANTRAGT])
            ->ueberschneidet($monat, $bis)->get()->groupBy('user_id');

        return view('zeiterfassung::abwesenheiten.kalender', compact('monat', 'personen', 'liste'));
    }

    /** @return array<string, mixed> */
    public static function pruefen(Request $request): array
    {
        $data = $request->validate([
            'art' => ['required', 'in:'.implode(',', array_keys(Abwesenheit::ARTEN))],
            'von' => ['required', 'date_format:Y-m-d'],
            'bis' => ['required', 'date_format:Y-m-d', 'after_or_equal:von'],
            'halbtag' => ['nullable', 'boolean'],
            'notiz' => ['nullable', 'string', 'max:500'],
        ]);
        $data['halbtag'] = (bool) ($data['halbtag'] ?? false) && $data['von'] === $data['bis'];

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function ueberschneidung(User $user, array $data): ?string
    {
        $da = Abwesenheit::where('user_id', $user->id)
            ->whereIn('status', [Abwesenheit::GENEHMIGT, Abwesenheit::BEANTRAGT])
            ->ueberschneidet(Carbon::parse($data['von']), Carbon::parse($data['bis']))
            ->first();

        return $da ? "Überschneidet sich mit {$da->artText()} {$da->von->format('d.m.')}–{$da->bis->format('d.m.Y')}." : null;
    }

    public static function auditEintrag(Abwesenheit $a): void
    {
        Korrektur::audit('zeiterfassung.abwesenheit', $a->artText().' '.$a->von->format('d.m.').'–'.$a->bis->format('d.m.Y'), $a->user);
    }
}
