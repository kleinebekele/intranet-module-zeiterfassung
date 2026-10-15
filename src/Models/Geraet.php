<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein verbundener PC/Mac mit dem Programm „Zeiterfassung“. Der Schlüssel wird nur
 * beim Verbinden einmal angezeigt; gespeichert ist allein sein Hash.
 */
class Geraet extends Model
{
    protected $table = 'zeit_geraete';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['zuletzt_am' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function neuesToken(): string
    {
        return 'zg_'.Str::random(48);
    }

    public static function zumToken(string $token): ?self
    {
        return $token === '' ? null : self::with('user')->where('token_hash', self::hash($token))->first();
    }
}
