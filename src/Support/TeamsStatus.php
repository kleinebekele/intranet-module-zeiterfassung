<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Teams-Status ↔ Arbeitszeit, freiwillig je Person.
 *
 * Die Person verbindet unter „Meine Zeiten" ihr eigenes Microsoft-Konto mit der delegierten
 * Berechtigung Presence.ReadWrite – das Intranet darf damit nur ihren eigenen Status lesen
 * und setzen. Gehen → Teams „Offline" (wie „Als offline anzeigen"), Pause → „Bin gleich zurück",
 * Kommen und Pause beenden → zurück auf den automatischen Status. Das PC-Programm sieht den Status über zeit-app/v1/status und fragt bei
 * einem Wechsel nach (gebucht wird nur nach Bestätigung).
 *
 * Nutzt dieselbe Entra-App wie die Microsoft-Anmeldung (MS_TENANT_ID/MS_CLIENT_ID/MS_CLIENT_SECRET).
 * In der App-Registrierung nötig: delegierte Berechtigung Presence.ReadWrite und die
 * Umleitungs-URI {@see self::umleitungsAdresse()}.
 */
class TeamsStatus
{
    public const SCOPES = ['openid', 'profile', 'offline_access', 'Presence.ReadWrite'];

    private const SITZUNG = 'zeit_teams_kopplung';

    public static function moeglich(): bool
    {
        return self::wert('tenant') !== null && self::wert('client_id') !== null && self::wert('client_secret') !== null;
    }

    public static function gekoppelt(User $user): bool
    {
        return DB::table('zeit_teams')->where('user_id', $user->id)->exists();
    }

    /** Öffentliche Adresse (APP_URL) – hinter dem Proxy wäre die Anfrage-Adresse intern. */
    public static function umleitungsAdresse(): string
    {
        return rtrim((string) config('app.url'), '/').route('module.zeiterfassung.meine.teams.zurueck', [], false);
    }

    // ── Kopplung ───────────────────────────────────────────────────

    public static function startUrl(Request $request): string
    {
        $state = Str::random(40);
        $pruefwert = Str::random(96);
        $request->session()->put(self::SITZUNG, ['state' => $state, 'pruefwert' => $pruefwert]);

        return self::endpunkt('authorize').'?'.http_build_query([
            'client_id' => self::wert('client_id'),
            'response_type' => 'code',
            'redirect_uri' => self::umleitungsAdresse(),
            'response_mode' => 'query',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $pruefwert, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'login_hint' => $request->user()?->email,
        ]);
    }

    /** @throws RuntimeException mit lesbarer Meldung */
    public static function abschliessen(Request $request, User $user): void
    {
        $merker = (array) $request->session()->pull(self::SITZUNG, []);
        if ($fehler = $request->query('error')) {
            throw new RuntimeException('Microsoft hat die Verbindung abgelehnt: '.($request->query('error_description') ?: $fehler));
        }
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '' || ! isset($merker['state']) || ! hash_equals($merker['state'], $state)) {
            throw new RuntimeException('Die Rückkehr von Microsoft passt nicht zu dieser Sitzung. Bitte noch einmal „Teams koppeln“ klicken.');
        }

        $tokens = self::tokenAnfrage([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::umleitungsAdresse(),
            'code_verifier' => $merker['pruefwert'],
            'scope' => implode(' ', self::SCOPES),
        ]);
        if (empty($tokens['refresh_token'])) {
            throw new RuntimeException('Microsoft hat kein Dauer-Token geliefert (offline_access fehlt?).');
        }

        $konto = self::kontoName((string) ($tokens['id_token'] ?? ''));
        DB::table('zeit_teams')->updateOrInsert(['user_id' => $user->id], [
            'ms_konto' => $konto,
            'refresh_token' => Crypt::encryptString($tokens['refresh_token']),
            'letzter_fehler' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::put(self::cacheToken($user), $tokens['access_token'], now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600) - 120)));
    }

    public static function trennen(User $user): void
    {
        DB::table('zeit_teams')->where('user_id', $user->id)->delete();
        Cache::forget(self::cacheToken($user));
        Cache::forget(self::cacheStatus($user));
    }

    // ── Lesen und Setzen ───────────────────────────────────────────

    /**
     * Aktueller Teams-Status der Person (kurz zwischengespeichert) – null ohne Kopplung oder bei Fehler.
     *
     * @return array{verfuegbarkeit: string, aktivitaet: string}|null
     */
    public static function lesen(User $user): ?array
    {
        if (! self::gekoppelt($user)) {
            return null;
        }

        return Cache::remember(self::cacheStatus($user), now()->addSeconds(50), function () use ($user) {
            try {
                $antwort = Http::withToken(self::zugriffsToken($user))->timeout(8)->get('https://graph.microsoft.com/v1.0/me/presence');
                if ($antwort->failed()) {
                    throw new RuntimeException('Teams-Status nicht lesbar (HTTP '.$antwort->status().')');
                }

                return ['verfuegbarkeit' => (string) $antwort->json('availability'), 'aktivitaet' => (string) $antwort->json('activity')];
            } catch (Throwable $e) {
                self::fehlerMerken($user, $e->getMessage());

                return null;
            }
        });
    }

    /** Teams-Status je Buchung: null = zurück auf den automatischen Status. */
    private const NACH_AKTION = [
        'gehen' => ['availability' => 'Offline', 'activity' => 'OffWork'],
        'pause' => ['availability' => 'BeRightBack', 'activity' => 'BeRightBack'],
        'kommen' => null,
        'weiter' => null,
    ];

    /**
     * Nach einer Buchung: Gehen → Offline, Pause → „Bin gleich zurück", Kommen und Pause
     * beenden → automatischer Status. Fehler werden nur gemerkt.
     */
    public static function nachBuchung(User $user, string $aktion, array $optionen = []): void
    {
        if (! array_key_exists($aktion, self::NACH_AKTION) || ! self::gekoppelt($user)) {
            return;
        }
        try {
            // Kein Ablauf: „Bin gleich zurück" und die Nachricht bleiben, bis die Pause wirklich beendet
            // wird – endet sie nicht, ist man eben noch in der Pause.
            $status = self::NACH_AKTION[$aktion];
            self::graph($user, $status ? 'setUserPreferredPresence' : 'clearUserPreferredPresence', $status ?? (object) []);

            // Statusnachricht zur Pause; beim Weiterarbeiten nur entfernen, wenn sie von uns stammt.
            $merker = 'zeit-teams-nachricht-'.$user->id;
            $nachricht = trim((string) ($optionen['nachricht'] ?? ''));
            if ($aktion === 'pause' && $nachricht !== '') {
                self::graph($user, 'setStatusMessage', ['statusMessage' => ['message' => ['content' => $nachricht, 'contentType' => 'text']]]);
                Cache::put($merker, true, now()->addDay());
            } elseif ($aktion !== 'pause' && Cache::pull($merker)) {
                self::graph($user, 'setStatusMessage', ['statusMessage' => ['message' => ['content' => '', 'contentType' => 'text']]]);
            }
            Cache::forget(self::cacheStatus($user));
            DB::table('zeit_teams')->where('user_id', $user->id)->update(['letzter_fehler' => null]);
        } catch (Throwable $e) {
            self::fehlerMerken($user, $e->getMessage());
        }
    }

    /** POST auf /me/presence/{aktion}; wirft bei Fehler. */
    private static function graph(User $user, string $aktion, array|object $daten): void
    {
        $antwort = Http::withToken(self::zugriffsToken($user))->timeout(8)
            ->post('https://graph.microsoft.com/v1.0/me/presence/'.$aktion, $daten);
        if ($antwort->failed()) {
            throw new RuntimeException('Teams: '.$aktion.' fehlgeschlagen (HTTP '.$antwort->status().': '.($antwort->json('error.message') ?: '').')');
        }
    }

    public static function letzterFehler(User $user): ?string
    {
        return DB::table('zeit_teams')->where('user_id', $user->id)->value('letzter_fehler');
    }

    public static function konto(User $user): ?string
    {
        return DB::table('zeit_teams')->where('user_id', $user->id)->value('ms_konto');
    }

    // ── Intern ─────────────────────────────────────────────────────

    private static function zugriffsToken(User $user): string
    {
        $token = Cache::get(self::cacheToken($user));
        if ($token) {
            return $token;
        }

        $zeile = DB::table('zeit_teams')->where('user_id', $user->id)->first();
        if (! $zeile) {
            throw new RuntimeException('Teams ist nicht gekoppelt.');
        }
        $tokens = self::tokenAnfrage([
            'grant_type' => 'refresh_token',
            'refresh_token' => Crypt::decryptString($zeile->refresh_token),
            'scope' => implode(' ', self::SCOPES),
        ]);
        if (! empty($tokens['refresh_token'])) {
            DB::table('zeit_teams')->where('user_id', $user->id)->update(['refresh_token' => Crypt::encryptString($tokens['refresh_token']), 'updated_at' => now()]);
        }
        Cache::put(self::cacheToken($user), $tokens['access_token'], now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600) - 120)));

        return $tokens['access_token'];
    }

    /** @return array<string, mixed> */
    private static function tokenAnfrage(array $felder): array
    {
        $antwort = Http::asForm()->timeout(15)->post(self::endpunkt('token'), $felder + [
            'client_id' => self::wert('client_id'),
            'client_secret' => self::wert('client_secret'),
        ]);
        if ($antwort->failed() || empty($antwort->json('access_token'))) {
            throw new RuntimeException('Microsoft-Token abgelehnt: '.($antwort->json('error_description') ?: 'HTTP '.$antwort->status()));
        }

        return (array) $antwort->json();
    }

    /** Anzeigename aus dem ID-Token (nur zur Anzeige, nicht zur Prüfung). */
    private static function kontoName(string $idToken): ?string
    {
        $teile = explode('.', $idToken);
        $daten = isset($teile[1]) ? json_decode(base64_decode(strtr($teile[1], '-_', '+/')), true) : null;

        return is_array($daten) ? ($daten['preferred_username'] ?? $daten['name'] ?? null) : null;
    }

    private static function fehlerMerken(User $user, string $text): void
    {
        DB::table('zeit_teams')->where('user_id', $user->id)->update(['letzter_fehler' => Str::limit($text, 490), 'updated_at' => now()]);
    }

    private static function endpunkt(string $name): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode((string) self::wert('tenant')).'/oauth2/v2.0/'.$name;
    }

    private static function wert(string $name): ?string
    {
        $wert = config('services.microsoft.'.$name);
        $wert = is_string($wert) ? trim($wert) : null;

        return $wert === '' ? null : $wert;
    }

    private static function cacheToken(User $user): string
    {
        return 'zeit-teams-token-'.$user->id;
    }

    private static function cacheStatus(User $user): string
    {
        return 'zeit-teams-status-'.$user->id;
    }
}
