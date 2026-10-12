<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use Illuminate\Database\Eloquent\Model;

/** Ein Zeitfenster an einem Wochentag: mindestens n Personen, optional m weitere. */
class Besetzung extends Model
{
    protected $table = 'zeit_abteilung_besetzung';

    protected $guarded = ['id'];
}
