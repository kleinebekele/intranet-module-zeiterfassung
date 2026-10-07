<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sollarbeitszeit einer Person ab einem Stichtag. Ein neues Modell ersetzt das
 * alte ab seinem gueltig_ab – vergangene Monate rechnen weiter mit dem alten.
 */
class Zeitmodell extends Model
{
    protected $table = 'zeit_modelle';

    public const TAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gueltig_ab' => 'date',
            'urlaubstage' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sollminuten für einen Wochentag (1 = Montag). */
    public function sollFuer(int $isoWochentag): int
    {
        return (int) $this->{"soll_{$isoWochentag}"};
    }

    public function wochenMinuten(): int
    {
        return array_sum(array_map(fn ($t) => $this->sollFuer($t), array_keys(self::TAGE)));
    }

    public function arbeitstageJeWoche(): int
    {
        return count(array_filter(array_keys(self::TAGE), fn ($t) => $this->sollFuer($t) > 0));
    }

    /**
     * Das am Tag gültige Modell aus einer (nach gueltig_ab aufsteigend sortierten) Liste.
     *
     * @param  Collection<int, self>  $modelle
     */
    public static function amTag(Collection $modelle, Carbon $tag): ?self
    {
        $d = $tag->toDateString();

        return $modelle->filter(fn (self $m) => $m->gueltig_ab->toDateString() <= $d)->last();
    }
}
