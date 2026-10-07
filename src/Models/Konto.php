<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use Illuminate\Database\Eloquent\Model;

/** Übertrag ins Jahr: Resturlaub aus dem Vorjahr und Stundensaldo (Minuten). */
class Konto extends Model
{
    protected $table = 'zeit_konten';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['urlaub_uebertrag' => 'decimal:1'];
    }
}
