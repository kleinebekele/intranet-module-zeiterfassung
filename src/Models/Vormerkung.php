<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Support\Korrektur;
use Intranet\Modules\Zeiterfassung\Support\Stempeluhr;
use Intranet\Modules\Zeiterfassung\Support\TeamsStatus;
use RuntimeException;

/**
 * Vormerkungen des PC-Programms (eine Zeile je Person): geplantes Pausenende, geplantes Gehen
 * und Ablauf einer Statusvorlage mit Rückfrage. Liegen auf dem Server, damit sie einen Neustart
 * des Programms und den Wechsel zwischen Geräten überstehen. Geplantes Gehen bucht der Server
 * selbst (Task Zeiterfassung/GeplantesGehen bzw. beim nächsten Status-Abruf) – auch wenn der
 * Rechner dann aus ist.
 */
class Vormerkung extends Model
{
    protected $table = 'zeit_vormerkungen';

    protected $guarded = ['id'];

    /** Länger verspätet, wird Gehen zur geplanten Zeit nachgetragen statt jetzt gebucht. */
    public const PUENKTLICH_MINUTEN = 2;

    protected function casts(): array
    {
        return ['pause_bis' => 'datetime', 'gehen_um' => 'datetime', 'vorlage_bis' => 'datetime'];
    }

    public static function setzen(User $user, array $werte): void
    {
        self::updateOrCreate(['user_id' => $user->id], $werte);
    }

    public static function von(User $user): ?self
    {
        return self::where('user_id', $user->id)->first();
    }

    /** Nach einer Buchung: Kommen/Gehen erledigt alles, Pause beenden das Pausenende. */
    public static function nachBuchung(User $user, string $aktion): void
    {
        $leeren = match ($aktion) {
            'kommen', 'gehen' => ['pause_bis' => null, 'gehen_um' => null, 'vorlage_bis' => null, 'vorlage_name' => null],
            'weiter' => ['pause_bis' => null],
            default => [],
        };
        if ($leeren !== []) {
            self::where('user_id', $user->id)->update($leeren);
        }
    }

    /** Für den Status des PC-Programms (nur, was im aktuellen Zustand gilt). */
    public static function fuerApp(User $user, string $zustand, ?Carbon $seit): array
    {
        $v = self::von($user);

        return [
            'pause_bis' => $zustand === 'pause' && $v?->pause_bis && $seit && $v->pause_bis->gt($seit) ? $v->pause_bis->toIso8601String() : null,
            'gehen_um' => $zustand !== 'weg' ? $v?->gehen_um?->toIso8601String() : null,
            'vorlage_bis' => $v?->vorlage_bis?->toIso8601String(),
            'vorlage_name' => $v?->vorlage_bis ? $v->vorlage_name : null,
        ];
    }

    /**
     * Fälliges geplantes Gehen buchen. Gibt die Meldung zurück (null = nichts fällig).
     * Die Vormerkung wird vorher atomar geleert – Task und Status-Abruf buchen nie doppelt.
     */
    public static function gehenBuchen(User $user, ?Carbon $jetzt = null): ?string
    {
        $jetzt ??= now();
        $v = self::where('user_id', $user->id)->whereNotNull('gehen_um')->where('gehen_um', '<=', $jetzt)->first();
        if (! $v) {
            return null;
        }
        $um = $v->gehen_um->copy();
        if (self::whereKey($v->id)->where('gehen_um', $v->gehen_um)->update(['gehen_um' => null]) !== 1) {
            return null; // gerade anderswo gebucht
        }

        $status = Stempeluhr::status($user, $jetzt);
        try {
            if ($status['zustand'] === 'weg') {
                return null;
            }
            if ($status['zustand'] === 'pause' || $um->diffInMinutes($jetzt) <= self::PUENKTLICH_MINUTEN) {
                Stempeluhr::buchen($user, 'gehen', 'app');
            } else {
                $offen = Stempeluhr::offen($user);
                $ende = $um->copy()->setTimezone(config('app.timezone'))->startOfMinute();
                if ($ende->lte($offen->beginn)) {
                    return null;
                }
                Korrektur::speichern($user, $user, $offen, $offen->beginn, $ende,
                    'Gehen im PC-Programm für '.$ende->format('H:i').' Uhr geplant', true, 'app');
                TeamsStatus::nachBuchung($user, 'gehen');
                self::nachBuchung($user, 'gehen');
            }
        } catch (RuntimeException $e) {
            report($e);

            return null;
        }

        return 'Geplantes Gehen um '.$um->format('H:i').' Uhr gebucht.';
    }
}
