<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Tag, der von den Wochenregeln abweicht: geschlossen (Rosenmontag,
 * Betriebsferien) oder mit eigenem Zeitfenster (Heiligabend, verkaufsoffener
 * Sonntag – das gilt auch an einem gesetzlichen Feiertag).
 */
class Sondertag extends Model
{
    protected $table = 'zeit_abteilung_sondertage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'datum' => 'date',
            'geschlossen' => 'boolean',
        ];
    }
}
