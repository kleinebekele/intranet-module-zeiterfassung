<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ein vom Tagesabschluss festgehaltener Verstoß; die Leitung bestätigt ihn als gesehen. */
class Verstoss extends Model
{
    protected $table = 'zeit_verstoesse';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'datum' => 'date',
            'gesehen_am' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
