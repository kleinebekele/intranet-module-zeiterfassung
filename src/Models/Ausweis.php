<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Womit sich jemand am Terminal meldet: Chip-Kennung oder persönlicher Code.
 *
 * Codes liegen nur als HMAC mit dem App-Schlüssel vor – eindeutig auffindbar,
 * aber nicht lesbar. Deshalb kann niemand einen Code nachschlagen, nur neu setzen.
 */
class Ausweis extends Model
{
    protected $table = 'zeit_ausweise';

    public const CHIP = 'chip';

    public const CODE = 'code';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Wie die Kantine: Groß/Klein und Trennzeichen egal. */
    public static function chipNormal(?string $roh): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $roh)));
    }

    public static function codeHash(string $code): string
    {
        return hash_hmac('sha256', 'zeit-code:'.trim($code), (string) config('app.key'));
    }

    /**
     * Wer steckt hinter der Eingabe am Terminal? Ziffern = Code, sonst Chip.
     * Chips, die in der Kantine schon zugeordnet sind, gelten mit.
     */
    public static function personFuer(string $eingabe): ?User
    {
        $eingabe = trim($eingabe);
        if ($eingabe === '') {
            return null;
        }

        if (preg_match('/^\d{4,10}$/', $eingabe)) {
            $treffer = self::where('art', self::CODE)->where('wert', self::codeHash($eingabe))->first();
            if ($treffer) {
                return $treffer->user;
            }
        }

        $chip = self::chipNormal($eingabe);
        if ($chip === '') {
            return null;
        }

        if ($treffer = self::where('art', self::CHIP)->where('wert', $chip)->first()) {
            return $treffer->user;
        }

        return self::ausKantine($chip);
    }

    /**
     * Neuer zufälliger Terminal-Code für die Person; ein alter verfällt. Der
     * Klartext wird nur zurückgegeben, gespeichert ist allein der Hash.
     */
    public static function neuerCode(User $user, int $laenge, ?string $wunsch = null): string
    {
        $laenge = max(4, min(10, $laenge));

        for ($versuch = 0; $versuch < 50; $versuch++) {
            $code = $wunsch ?? str_pad((string) random_int(0, 10 ** $laenge - 1), $laenge, '0', STR_PAD_LEFT);
            $hash = self::codeHash($code);
            $belegt = self::where('art', self::CODE)->where('wert', $hash)->where('user_id', '!=', $user->id)->exists();
            if (! $belegt) {
                self::where('art', self::CODE)->where('user_id', $user->id)->delete();
                self::create(['user_id' => $user->id, 'art' => self::CODE, 'wert' => $hash, 'bezeichnung' => 'Terminal-Code']);

                return $code;
            }
            if ($wunsch !== null) {
                throw new \RuntimeException('Dieser Code ist nicht verfügbar – bitte einen anderen wählen.');
            }
        }

        throw new \RuntimeException('Es ließ sich kein freier Code finden.');
    }

    private static function ausKantine(string $chip): ?User
    {
        try {
            if (! Schema::hasTable('kantine_nfc_chips')) {
                return null;
            }
            $userId = DB::table('kantine_nfc_chips')->where('uid', $chip)->whereNull('returned_at')->value('user_id');
        } catch (\Throwable) {
            return null;
        }

        return $userId ? User::find($userId) : null;
    }
}
