<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Ein Stempel-Terminal (Tablet im Kiosk-Modus). Es meldet sich über ein
 * geheimes Kürzel in der Adresse an; gespeichert ist nur dessen Hash. Optional
 * zusätzlich auf Netze beschränkt.
 */
class Terminal extends Model
{
    protected $table = 'zeit_terminals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aktiv' => 'boolean',
            'zuletzt_am' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function neuesToken(): string
    {
        return Str::random(40);
    }

    public static function zumToken(string $token): ?self
    {
        return self::where('token_hash', self::hash($token))->where('aktiv', true)->first();
    }

    /** @return list<string> */
    public function netzListe(): array
    {
        $text = preg_replace('/#[^\n]*/', '', (string) $this->netze);

        return array_values(array_unique(preg_split('/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    public function erlaubtIp(?string $ip): bool
    {
        $netze = $this->netzListe();

        return $netze === [] || ($ip !== null && IpUtils::checkIp($ip, $netze));
    }
}
