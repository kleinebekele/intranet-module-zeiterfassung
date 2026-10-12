<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Der Dienstplan einer Abteilung für einen Monat. Mitglieder sehen ihn erst, wenn er veröffentlicht ist. */
class Dienstplan extends Model
{
    protected $table = 'zeit_dienstplaene';

    public const ENTWURF = 'entwurf';

    public const VEROEFFENTLICHT = 'veroeffentlicht';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monat' => 'date',
            'veroeffentlicht_am' => 'datetime',
        ];
    }

    public function abteilung(): BelongsTo
    {
        return $this->belongsTo(Abteilung::class);
    }

    public function schichten(): HasMany
    {
        return $this->hasMany(Schicht::class)->orderBy('datum')->orderBy('von');
    }

    public function ersteller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'erstellt_von');
    }

    /** whereDate statt where: SQLite speichert das Datum mit Uhrzeit. */
    public static function fuer(Abteilung $abteilung, \Illuminate\Support\Carbon $monat): ?self
    {
        return self::where('abteilung_id', $abteilung->id)
            ->whereDate('monat', $monat->copy()->startOfMonth()->toDateString())
            ->first();
    }

    public function istVeroeffentlicht(): bool
    {
        return $this->status === self::VEROEFFENTLICHT;
    }
}
