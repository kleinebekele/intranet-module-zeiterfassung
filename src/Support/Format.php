<?php

namespace Intranet\Modules\Zeiterfassung\Support;

/** Minuten als Stunden:Minuten. */
class Format
{
    public static function dauer(int $minuten, bool $vorzeichen = false): string
    {
        $zeichen = $minuten < 0 ? '−' : ($vorzeichen && $minuten > 0 ? '+' : '');
        $m = abs($minuten);

        return $zeichen.intdiv($m, 60).':'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Urlaubstage: 12 / 12,5 */
    public static function tage(float $tage): string
    {
        return rtrim(rtrim(number_format($tage, 1, ',', '.'), '0'), ',');
    }

    /** „7:30" / „7,5" → Minuten; null bei Unsinn. */
    public static function minutenAus(?string $text): ?int
    {
        $text = trim((string) $text);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $text, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $text)) {
            return (int) round((float) str_replace(',', '.', $text) * 60);
        }

        return null;
    }
}
