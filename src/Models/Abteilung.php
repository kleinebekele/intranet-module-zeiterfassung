<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Intranet\Modules\Zeiterfassung\Support\Einstellungen;

/**
 * Eine Abteilung (Filiale, Lager, Empfang …) für die Dienstplanung: wann sie
 * wie besetzt sein muss und wer zu ihr gehört.
 */
class Abteilung extends Model
{
    protected $table = 'zeit_abteilungen';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kernzeiten' => 'array',
            'aushilfen_voll' => 'boolean',
        ];
    }

    public function personen(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'zeit_abteilung_personen', 'abteilung_id', 'user_id')
            ->withPivot(['aushilfe', 'wochenstunden', 'max_monat', 'max_tage_woche', 'freie_tage', 'position'])
            ->orderBy('zeit_abteilung_personen.aushilfe')
            ->orderBy('zeit_abteilung_personen.position')
            ->orderBy('users.name');
    }

    public function besetzung(): HasMany
    {
        return $this->hasMany(Besetzung::class)->orderBy('wochentag')->orderBy('von');
    }

    public function sondertage(): HasMany
    {
        return $this->hasMany(Sondertag::class)->orderBy('datum');
    }

    public function dienstplaene(): HasMany
    {
        return $this->hasMany(Dienstplan::class);
    }

    public function land(): string
    {
        return $this->bundesland ?: Einstellungen::get('bundesland');
    }
}
