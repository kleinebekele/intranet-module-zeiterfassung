<?php

namespace Intranet\Modules\Zeiterfassung\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Geraet;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use Symfony\Component\HttpFoundation\Response;

/**
 * Das PC-Programm meldet sich mit dem Geräteschlüssel (Authorization: Bearer …).
 * Es stempelt nur für die Person, der das Gerät gehört, und nur, solange sie im
 * Intranet stempeln darf (Rolle zeit-homeoffice bzw. Verwaltung).
 */
class GeraetZugang
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Module::where('key', 'zeiterfassung')->where('is_enabled', true)->exists()) {
            return response()->json(['ok' => false, 'meldung' => 'Die Zeiterfassung ist nicht aktiv.'], 404);
        }

        $geraet = Geraet::zumToken((string) $request->bearerToken());
        if (! $geraet || ! $geraet->user) {
            return response()->json(['ok' => false, 'meldung' => 'Gerät nicht verbunden oder Verbindung entfernt.'], 401);
        }
        if (! Rechte::darfWebStempeln($geraet->user)) {
            return response()->json(['ok' => false, 'meldung' => 'Stempeln am PC ist für dich nicht freigeschaltet.'], 403);
        }

        if (! $geraet->zuletzt_am || $geraet->zuletzt_am->lt(now()->subMinute())) {
            $geraet->forceFill(['zuletzt_am' => now()])->saveQuietly();
        }

        $request->attributes->set('zeit_geraet', $geraet);
        $request->setUserResolver(fn () => $geraet->user);

        return $next($request);
    }
}
