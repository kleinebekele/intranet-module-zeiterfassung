<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Urlaub, Krankheit & Co. Ein Zeitraum von–bis (ganze Tage), optional ein
 * halber Tag (nur wenn von = bis).
 *
 * „gutschrift" heißt: der Tag zählt, als hätte die Person ihr Soll gearbeitet.
 * Überstundenabbau schreibt nichts gut – das Soll bleibt stehen und der Saldo sinkt.
 */
class Abwesenheit extends Model
{
    protected $table = 'zeit_abwesenheiten';

    public const BEANTRAGT = 'beantragt';

    public const GENEHMIGT = 'genehmigt';

    public const ABGELEHNT = 'abgelehnt';

    public const STORNIERT = 'storniert';

    /** art => [Bezeichnung, Gutschrift, braucht Genehmigung, zählt als Urlaub] */
    public const ARTEN = [
        'urlaub' => ['Urlaub', true, true, true],
        'krank' => ['Krank', true, false, false],
        'kind_krank' => ['Kind krank', true, false, false],
        'sonderurlaub' => ['Sonderurlaub', true, true, false],
        'fortbildung' => ['Fortbildung', true, true, false],
        'dienstreise' => ['Dienstreise', true, true, false],
        'ueberstunden' => ['Überstundenabbau', false, true, false],
        'unbezahlt' => ['Unbezahlt frei', false, true, false],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'von' => 'date',
            'bis' => 'date',
            'halbtag' => 'boolean',
            'entschieden_am' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entscheider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entschieden_von');
    }

    public function scopeGenehmigt(Builder $q): Builder
    {
        return $q->where('status', self::GENEHMIGT);
    }

    public function scopeUeberschneidet(Builder $q, Carbon $von, Carbon $bis): Builder
    {
        return $q->whereDate('von', '<=', $bis->toDateString())->whereDate('bis', '>=', $von->toDateString());
    }

    public function artText(): string
    {
        return self::ARTEN[$this->art][0] ?? $this->art;
    }

    public function schreibtGut(): bool
    {
        return self::ARTEN[$this->art][1] ?? false;
    }

    public static function brauchtGenehmigung(string $art): bool
    {
        return self::ARTEN[$art][2] ?? true;
    }

    public function istUrlaub(): bool
    {
        return self::ARTEN[$this->art][3] ?? false;
    }

    public function umfasst(Carbon $tag): bool
    {
        $d = $tag->toDateString();

        return $this->von->toDateString() <= $d && $this->bis->toDateString() >= $d;
    }

    public function statusText(): string
    {
        return match ($this->status) {
            self::BEANTRAGT => 'beantragt',
            self::GENEHMIGT => 'genehmigt',
            self::ABGELEHNT => 'abgelehnt',
            self::STORNIERT => 'storniert',
            default => $this->status,
        };
    }
}
