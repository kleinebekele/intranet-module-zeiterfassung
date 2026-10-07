<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\Setting;

/**
 * Einstellungen der Zeiterfassung (Core-Einstellungen mit Präfix
 * „zeiterfassung."). Die Vorgaben entsprechen dem Arbeitszeitgesetz.
 */
class Einstellungen
{
    public const VORGABEN = [
        'bundesland' => 'NW',
        'pause_abziehen' => '1',   // fehlende gesetzliche Pause von der Arbeitszeit abziehen
        'max_tag' => '600',        // § 3 ArbZG: höchstens 10 Stunden
        'max_block' => '360',      // § 4 ArbZG: nicht länger als 6 Stunden ohne Pause
        'ruhezeit' => '660',       // § 5 ArbZG: 11 Stunden Ruhe
        'max_woche' => '2880',     // 48 Stunden (6 Werktage à 8 h)
        'code_laenge' => '6',
        'sonntag_warnen' => '1',   // § 9 ArbZG: Sonn- und Feiertagsruhe
    ];

    public static function get(string $schluessel): string
    {
        return (string) Setting::get('zeiterfassung.'.$schluessel, self::VORGABEN[$schluessel] ?? '');
    }

    public static function int(string $schluessel): int
    {
        return (int) self::get($schluessel);
    }

    public static function bool(string $schluessel): bool
    {
        return self::get($schluessel) === '1';
    }

    public static function set(string $schluessel, string $wert): void
    {
        Setting::set('zeiterfassung.'.$schluessel, $wert);
    }
}
