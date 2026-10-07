<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Zeiterfassung\Models\Gruppe;

/**
 * Wer darf was in der Zeiterfassung.
 *
 * Den Zugang zu den Seiten regelt der Core (Menüpunkt ↔ Rolle). Hier steht,
 * was darüber hinausgeht:
 *  - Teilnehmer: wer eine Rolle hat, die als Zeiterfassungs-Gruppe eingetragen ist.
 *  - Leitung: sieht und ändert die Zeiten der Mitglieder ihrer Gruppen, gibt frei.
 *  - Verwaltung (Rolle zeit-verwaltung, Admins): alle Teilnehmer, eigene Nachträge sofort gültig.
 *  - Eigene Nachträge: zeit-nachtrag-frei sofort gültig, zeit-nachtrag als Antrag, sonst gar nicht.
 *  - Stempeln im Intranet (Homeoffice): zeit-homeoffice.
 */
class Rechte
{
    public const VERWALTUNG = 'zeit-verwaltung';

    public const NACHTRAG_FREI = 'zeit-nachtrag-frei';

    public const NACHTRAG = 'zeit-nachtrag';

    public const HOMEOFFICE = 'zeit-homeoffice';

    /** @var array<string, mixed> Zwischenspeicher je Anfrage */
    private static array $merker = [];

    public static function vergessen(): void
    {
        self::$merker = [];
    }

    public static function istVerwaltung(?User $user): bool
    {
        return $user !== null && ($user->is_admin || $user->hatRolle(self::VERWALTUNG));
    }

    public static function darfWebStempeln(?User $user): bool
    {
        return $user !== null && self::istTeilnehmer($user)
            && (self::istVerwaltung($user) || $user->hatRolle(self::HOMEOFFICE));
    }

    /** 'frei' = sofort gültig, 'antrag' = braucht Freigabe, null = nicht erlaubt. */
    public static function nachtragModus(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        if (self::istVerwaltung($user) || $user->hatRolle(self::NACHTRAG_FREI)) {
            return 'frei';
        }

        return $user->hatRolle(self::NACHTRAG) ? 'antrag' : null;
    }

    /** Rollen-Schlüssel aller Zeiterfassungs-Gruppen (nur Rollen, die gerade gelten). */
    public static function gruppenRollen(): Collection
    {
        return self::$merker['rollen'] ??= Gruppe::pluck('role_id')
            ->diff(Role::inaktiveSchluessel())
            ->values();
    }

    /** @return Collection<int, int> */
    public static function teilnehmerIds(): Collection
    {
        return self::$merker['teilnehmer'] ??= DB::table('user_roles')
            ->whereIn('role_id', self::gruppenRollen())
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
    }

    public static function istTeilnehmer(?User $user): bool
    {
        return $user !== null && self::teilnehmerIds()->contains($user->id);
    }

    /** Gruppen, die der Benutzer leitet. */
    public static function geleiteteGruppen(User $user): Collection
    {
        return self::$merker['geleitet.'.$user->id] ??= Gruppe::whereHas('leitung', fn ($q) => $q->where('users.id', $user->id))
            ->get()
            ->filter(fn (Gruppe $g) => self::gruppenRollen()->contains($g->role_id))
            ->values();
    }

    public static function istLeitung(?User $user): bool
    {
        return $user !== null && self::geleiteteGruppen($user)->isNotEmpty();
    }

    /**
     * Wessen Zeiten darf der Benutzer sehen und ändern? Verwaltung: alle
     * Teilnehmer. Leitung: Mitglieder ihrer Gruppen – außer sich selbst (eigene
     * Zeiten laufen über die eigenen Nachtragsrechte).
     *
     * @return Collection<int, int>
     */
    public static function verwalteteIds(User $user): Collection
    {
        return self::$merker['verwaltet.'.$user->id] ??= (function () use ($user) {
            if (self::istVerwaltung($user)) {
                return self::teilnehmerIds();
            }

            $rollen = self::geleiteteGruppen($user)->pluck('role_id');
            if ($rollen->isEmpty()) {
                return collect();
            }

            return DB::table('user_roles')->whereIn('role_id', $rollen)->distinct()->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === $user->id)
                ->values();
        })();
    }

    public static function darfVerwalten(User $wer, int $personId): bool
    {
        return self::verwalteteIds($wer)->contains($personId)
            // Verwaltung darf auch ihre eigenen Zeiten direkt ändern („Chef darf immer").
            || (self::istVerwaltung($wer) && $wer->id === $personId);
    }

    public static function darfTeam(?User $user): bool
    {
        return $user !== null && (self::istVerwaltung($user) || self::istLeitung($user));
    }
}
