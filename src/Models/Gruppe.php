<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eine Zeiterfassungs-Gruppe ist eine vorhandene Rolle (Mitarbeiter, Lehrer,
 * Filiale X …). Wer die Rolle hat, nimmt an der Zeiterfassung teil; die
 * Leitung sieht und bearbeitet die Zeiten dieser Leute.
 */
class Gruppe extends Model
{
    protected $table = 'zeit_gruppen';

    protected $guarded = ['id'];

    public function rolle(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function leitung(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'zeit_gruppe_leitung', 'gruppe_id', 'user_id');
    }

    public function name(): string
    {
        return $this->rolle?->name ?? $this->role_id;
    }
}
