<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use Illuminate\Support\Carbon;

/**
 * Gesetzliche Feiertage je Bundesland – selbst berechnet (Ostern nach Gauß),
 * damit die Zeiterfassung ohne Fremddienst auskommt.
 */
class Feiertage
{
    public const LAENDER = [
        'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg',
        'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein',
        'TH' => 'Thüringen',
    ];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** Name des Feiertags oder null. */
    public static function name(Carbon $tag, string $land): ?string
    {
        return self::fuerJahr($tag->year, $land)[$tag->toDateString()] ?? null;
    }

    /** @return array<string, string> Datum (Y-m-d) => Name */
    public static function fuerJahr(int $jahr, string $land): array
    {
        $schluessel = "{$jahr}-{$land}";
        if (isset(self::$cache[$schluessel])) {
            return self::$cache[$schluessel];
        }

        $ostern = self::ostersonntag($jahr);
        $o = fn (int $tage) => $ostern->copy()->addDays($tage)->toDateString();
        $d = fn (int $m, int $t) => sprintf('%04d-%02d-%02d', $jahr, $m, $t);

        $liste = [
            $d(1, 1) => 'Neujahr',
            $o(-2) => 'Karfreitag',
            $o(1) => 'Ostermontag',
            $d(5, 1) => 'Tag der Arbeit',
            $o(39) => 'Christi Himmelfahrt',
            $o(50) => 'Pfingstmontag',
            $d(10, 3) => 'Tag der Deutschen Einheit',
            $d(12, 25) => '1. Weihnachtstag',
            $d(12, 26) => '2. Weihnachtstag',
        ];

        if (in_array($land, ['BW', 'BY', 'ST'], true)) {
            $liste[$d(1, 6)] = 'Heilige Drei Könige';
        }
        if ($land === 'BE' || ($land === 'MV' && $jahr >= 2023)) {
            $liste[$d(3, 8)] = 'Internationaler Frauentag';
        }
        if ($land === 'BB') {
            $liste[$o(0)] = 'Ostersonntag';
            $liste[$o(49)] = 'Pfingstsonntag';
        }
        if (in_array($land, ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'], true)) {
            $liste[$o(60)] = 'Fronleichnam';
        }
        if (in_array($land, ['BY', 'SL'], true)) {
            $liste[$d(8, 15)] = 'Mariä Himmelfahrt';
        }
        if ($land === 'TH' && $jahr >= 2019) {
            $liste[$d(9, 20)] = 'Weltkindertag';
        }
        if (in_array($land, ['BB', 'MV', 'SN', 'ST', 'TH', 'HB', 'HH', 'NI', 'SH'], true)) {
            $liste[$d(10, 31)] = 'Reformationstag';
        }
        if (in_array($land, ['BW', 'BY', 'NW', 'RP', 'SL'], true)) {
            $liste[$d(11, 1)] = 'Allerheiligen';
        }
        if ($land === 'SN') {
            // Mittwoch vor dem 23. November
            $bb = Carbon::create($jahr, 11, 22);
            while ($bb->dayOfWeekIso !== 3) {
                $bb->subDay();
            }
            $liste[$bb->toDateString()] = 'Buß- und Bettag';
        }

        ksort($liste);

        return self::$cache[$schluessel] = $liste;
    }

    public static function ostersonntag(int $jahr): Carbon
    {
        $a = $jahr % 19;
        $b = intdiv($jahr, 100);
        $c = $jahr % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $monat = intdiv($h + $l - 7 * $m + 114, 31);
        $tag = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($jahr, $monat, $tag)->startOfDay();
    }
}
