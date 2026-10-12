<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Intranet\Modules\Zeiterfassung\Support\Planer;

/** Eine geplante Schicht: Anwesenheit von–bis, darin die Pause. */
class Schicht extends Model
{
    protected $table = 'zeit_dienstplan_schichten';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datum' => 'date'];
    }

    public function dienstplan(): BelongsTo
    {
        return $this->belongsTo(Dienstplan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Anwesenheit in Minuten (über Mitternacht nicht vorgesehen). */
    public function dauer(): int
    {
        return max(0, Planer::minute($this->bis) - Planer::minute($this->von));
    }

    /** Arbeitszeit = Anwesenheit ohne Pause. */
    public function arbeit(): int
    {
        return max(0, $this->dauer() - (int) $this->pause);
    }
}
