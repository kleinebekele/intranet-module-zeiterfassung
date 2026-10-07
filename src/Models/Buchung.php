<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Arbeitsabschnitt von beginn bis ende. Pausen werden nicht gespeichert –
 * sie sind die Lücken zwischen zwei Abschnitten desselben Tages.
 *
 * Offen (ende = null) heißt: die Person ist gerade da. ende_art sagt, womit ein
 * Abschnitt endete („pause" oder „gehen") – daran erkennt das Terminal, ob
 * jemand in der Pause oder schon gegangen ist.
 *
 * Nie überschreiben: Änderungen legen eine neue Zeile mit ersetzt_id an, die
 * alte bekommt den Status „ersetzt" (siehe Support\Korrektur).
 */
class Buchung extends Model
{
    protected $table = 'zeit_buchungen';

    public const GUELTIG = 'gueltig';

    public const BEANTRAGT = 'beantragt';

    public const ABGELEHNT = 'abgelehnt';

    public const ERSETZT = 'ersetzt';

    public const GELOESCHT = 'geloescht';

    public const ERLEDIGT = 'erledigt';

    public const QUELLEN = [
        'terminal' => 'Terminal',
        'web' => 'Intranet',
        'nachtrag' => 'Nachtrag',
        'leitung' => 'Leitung',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'beginn' => 'datetime',
            'ende' => 'datetime',
            'entschieden_am' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ersetzt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ersetzt_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function erfasser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'erfasst_von');
    }

    public function entscheider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entschieden_von');
    }

    public function scopeGueltig(Builder $q): Builder
    {
        return $q->where('status', self::GUELTIG);
    }

    /** Abschnitte, die an einem der Tage [von, bis] beginnen. */
    public function scopeZwischen(Builder $q, Carbon $von, Carbon $bis): Builder
    {
        return $q->where('beginn', '>=', $von->copy()->startOfDay())
            ->where('beginn', '<=', $bis->copy()->endOfDay());
    }

    public function istOffen(): bool
    {
        return $this->ende === null;
    }

    /** Dauer in Minuten; offene Abschnitte bis jetzt. */
    public function minuten(?Carbon $jetzt = null): int
    {
        $ende = $this->ende ?? ($jetzt ?? now());

        return max(0, intdiv($ende->getTimestamp() - $this->beginn->getTimestamp(), 60));
    }

    public function quelleText(): string
    {
        return self::QUELLEN[$this->quelle] ?? $this->quelle;
    }

    public function antragText(): string
    {
        return match ($this->antrag) {
            'neu' => 'Nachtrag',
            'aendern' => 'Änderung',
            'loeschen' => 'Löschung',
            default => '',
        };
    }
}
