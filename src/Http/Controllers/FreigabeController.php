<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use RuntimeException;

/** Offene Anträge der verwalteten Personen. Eigene Anträge gibt immer jemand anderes frei. */
class FreigabeController
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::darfTeam($user), 403);
        $ids = Rechte::verwalteteIds($user)->reject(fn ($id) => $id === $user->id);

        return view('zeiterfassung::freigaben.index', [
            'buchungen' => Buchung::with(['user', 'ersetzt'])->where('status', Buchung::BEANTRAGT)
                ->whereIn('user_id', $ids)->orderBy('beginn')->get(),
            'abwesenheiten' => Abwesenheit::with('user')->where('status', Abwesenheit::BEANTRAGT)
                ->whereIn('user_id', $ids)->orderBy('von')->get(),
            'erledigt' => Buchung::with(['user', 'entscheider'])->whereIn('user_id', $ids)
                ->whereNotNull('antrag')->whereNotIn('status', [Buchung::BEANTRAGT])
                ->where('entschieden_am', '>=', now()->subDays(14))
                ->whereColumn('entschieden_von', '!=', 'erfasst_von')
                ->latest('entschieden_am')->limit(30)->get(),
        ]);
    }

    public function buchung(Request $request, Buchung $buchung)
    {
        $this->darf($request, $buchung->user_id);

        try {
            Korrektur::entscheiden($buchung, $request->user(), $request->input('entscheid') === 'ja', $request->input('notiz'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $request->input('entscheid') === 'ja' ? 'Freigegeben.' : 'Abgelehnt.');
    }

    public function abwesenheit(Request $request, Abwesenheit $abwesenheit)
    {
        $this->darf($request, $abwesenheit->user_id);

        try {
            Korrektur::abwesenheitEntscheiden($abwesenheit, $request->user(), $request->input('entscheid') === 'ja', $request->input('notiz'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $request->input('entscheid') === 'ja' ? 'Genehmigt.' : 'Abgelehnt.');
    }

    private function darf(Request $request, int $personId): void
    {
        $user = $request->user();
        abort_if($personId === $user->id, 403, 'Eigene Anträge gibt jemand anderes frei.');
        abort_unless(Rechte::verwalteteIds($user)->contains($personId), 403);
    }
}
