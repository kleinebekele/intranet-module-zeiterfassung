<?php

namespace Intranet\Modules\Zeiterfassung\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Intranet\Modules\Zeiterfassung\Models\Terminal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Das Terminal läuft ohne Intranet-Anmeldung. Es weist sich über den geheimen
 * Schlüssel in der Adresse aus und muss zusätzlich aus einem der eingetragenen
 * Netze kommen. Ein Terminal ohne Netz ist gesperrt.
 */
class TerminalZugang
{
    public const OHNE_NETZ = 'Dieses Terminal ist für kein Netz freigegeben – bitte in der Verwaltung Netze eintragen.';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Module::where('key', 'zeiterfassung')->where('is_enabled', true)->exists(), 404);

        // Falscher Schlüssel oder fremdes Netz: schlichtes 403 ohne Erklärung.
        $terminal = Terminal::zumToken((string) $request->route('schluessel'));
        abort_unless($terminal, 403);

        // Gültiger Schlüssel, aber kein Netz eingetragen: gesperrt, mit Hinweis für die Einrichtung.
        if (! $terminal->hatNetze()) {
            return $request->expectsJson()
                ? response()->json(['found' => false, 'ok' => false, 'meldung' => self::OHNE_NETZ], 403)
                : response()->view('zeiterfassung::terminal.gesperrt', ['terminal' => $terminal, 'meldung' => self::OHNE_NETZ], 403);
        }

        abort_unless($terminal->erlaubtIp($request->ip()), 403);

        if (! $terminal->zuletzt_am || $terminal->zuletzt_am->lt(now()->subMinute())) {
            $terminal->forceFill(['zuletzt_am' => now()])->saveQuietly();
        }

        $request->attributes->set('zeit_terminal', $terminal);

        return $next($request);
    }
}
