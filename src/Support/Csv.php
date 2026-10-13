<?php

namespace Intranet\Modules\Zeiterfassung\Support;

/**
 * CSV-Zeilen für Excel & Co. Zellen, die mit = + - @ Tab oder CR beginnen,
 * bekommen ein ' vorangestellt – sonst könnte ein Name oder eine Notiz als
 * Formel ausgeführt werden (CSV-/Formula-Injection). Reine Zahlen und Zeiten
 * wie „-1:30" (negativer Saldo) bleiben unverändert.
 */
class Csv
{
    /** @param resource $out */
    public static function zeile($out, array $felder, string $trenner = ';'): void
    {
        fputcsv($out, array_map([self::class, 'zelle'], $felder), $trenner);
    }

    public static function zelle(mixed $wert): mixed
    {
        if (! is_string($wert) || $wert === '') {
            return $wert;
        }
        if (preg_match('/^-?\d+([.,:]\d+)*$/', $wert)) {
            return $wert;
        }

        return in_array($wert[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$wert : $wert;
    }
}
