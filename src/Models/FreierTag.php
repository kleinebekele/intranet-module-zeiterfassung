<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freier Tag für die Dienstplanung (Wunschfrei): Die Person wird an dem Tag in keiner
 * Abteilung eingeplant. Anders als Urlaub keine Abwesenheit – das Wochensoll bleibt und
 * verteilt sich auf die übrigen Tage.
 */
class FreierTag extends Model
{
    protected $table = 'zeit_dienstplan_frei';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datum' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
