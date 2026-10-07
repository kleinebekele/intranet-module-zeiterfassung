<?php

namespace Intranet\Modules\Zeiterfassung\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Terminal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Das Terminal läuft ohne Intranet-Anmeldung. Es weist sich über den geheimen
 * Schlüssel in der Adresse aus; ist das Terminal auf Netze beschränkt, muss
 * die Anfrage zusätzlich von dort kommen.
 */
class TerminalZugang
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Module::where('key', 'zeiterfassung')->where('is_enabled', true)->exists(), 404);

        $terminal = Terminal::zumToken((string) $request->route('schluessel'));
        if (! $terminal) {
            return response()->view('zeiterfassung::terminal.gesperrt', ['grund' => 'Dieses Terminal ist nicht (mehr) eingerichtet.'], 403);
        }
        if (! $terminal->erlaubtIp($request->ip())) {
            return response()->view('zeiterfassung::terminal.gesperrt', [
                'grund' => 'Dieses Terminal ist von hier aus nicht freigegeben (Adresse '.$request->ip().').',
            ], 403);
        }

        if (! $terminal->zuletzt_am || $terminal->zuletzt_am->lt(now()->subMinute())) {
            $terminal->forceFill(['zuletzt_am' => now()])->saveQuietly();
        }

        $request->attributes->set('zeit_terminal', $terminal);

        return $next($request);
    }
}
