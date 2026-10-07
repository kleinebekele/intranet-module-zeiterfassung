<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use RuntimeException;

/**
 * Kommen, Pause, Gehen – für Terminal und Intranet derselbe Ablauf.
 *
 * Zustand einer Person:
 *  - da:    ein offener Abschnitt läuft
 *  - pause: kein offener Abschnitt, der letzte heutige endete mit „pause"
 *  - weg:   sonst
 */
class Stempeluhr
{
    public const AKTIONEN = [
        'kommen' => 'Kommen',
        'pause' => 'Pause',
        'weiter' => 'Pause beenden',
        'gehen' => 'Gehen',
    ];

    /**
     * @return array{zustand: string, seit: ?Carbon, heute: int, block: int, aktionen: list<string>}
     */
    public static function status(User $user, ?Carbon $jetzt = null): array
    {
        $jetzt ??= now();
        $offen = self::offen($user);
        $heute = Buchung::gueltig()->where('user_id', $user->id)
            ->zwischen($jetzt, $jetzt)->orderBy('beginn')->get();

        // Ein offener Abschnitt von gestern (Nachtschicht oder vergessen) gehört dazu.
        if ($offen && ! $heute->contains('id', $offen->id)) {
            $heute->prepend($offen);
        }

        $letzter = $heute->last();
        $zustand = $offen ? 'da' : (($letzter && $letzter->ende_art === 'pause') ? 'pause' : 'weg');

        return [
            'zustand' => $zustand,
            'seit' => $offen?->beginn ?? $letzter?->ende,
            'heute' => $heute->sum(fn (Buchung $b) => $b->minuten($jetzt)),
            'block' => $offen ? Rechner::laengsterBlock($heute, $jetzt) : 0,
            'aktionen' => match ($zustand) {
                'da' => ['pause', 'gehen'],
                'pause' => ['weiter', 'gehen'],
                default => ['kommen'],
            },
        ];
    }

    public static function offen(User $user): ?Buchung
    {
        return Buchung::gueltig()->where('user_id', $user->id)->whereNull('ende')->latest('beginn')->first();
    }

    /**
     * Führt eine Aktion aus. Wirft RuntimeException mit Klartext, wenn sie im
     * aktuellen Zustand keinen Sinn ergibt (doppelt gedrückt …).
     */
    public static function buchen(User $user, string $aktion, string $quelle, ?int $terminalId = null): Buchung
    {
        return DB::transaction(function () use ($user, $aktion, $quelle, $terminalId) {
            // Sperre gegen Doppelklick: zwei Anfragen gleichzeitig dürfen nicht zwei Abschnitte öffnen.
            User::whereKey($user->id)->lockForUpdate()->first();

            $jetzt = now()->startOfMinute();
            $status = self::status($user, $jetzt);

            if (! in_array($aktion, $status['aktionen'], true)) {
                throw new RuntimeException(match ($status['zustand']) {
                    'da' => 'Du bist bereits eingestempelt.',
                    'pause' => 'Du bist gerade in der Pause.',
                    default => 'Du bist nicht eingestempelt.',
                });
            }

            if ($aktion === 'kommen' || $aktion === 'weiter') {
                return Buchung::create([
                    'user_id' => $user->id,
                    'beginn' => $jetzt,
                    'quelle' => $quelle,
                    'status' => Buchung::GUELTIG,
                    'terminal_id' => $terminalId,
                    'erfasst_von' => auth()->id() ?? $user->id,
                ]);
            }

            if ($aktion === 'gehen' && $status['zustand'] === 'pause') {
                // Aus der Pause nach Hause: die Pause war das Ende.
                $letzter = Buchung::gueltig()->where('user_id', $user->id)->whereNotNull('ende')->latest('ende')->first();
                $letzter->update(['ende_art' => 'gehen']);

                return $letzter;
            }

            $offen = self::offen($user);
            // Ein Abschnitt darf nicht vor seinem Beginn enden (Kommen und Gehen in derselben Minute).
            $offen->update([
                'ende' => $jetzt->lt($offen->beginn) ? $offen->beginn : $jetzt,
                'ende_art' => $aktion === 'pause' ? 'pause' : 'gehen',
            ]);

            return $offen;
        });
    }
}
