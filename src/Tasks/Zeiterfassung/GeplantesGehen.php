<?php

namespace Intranet\Modules\Zeiterfassung\Tasks\Zeiterfassung;

use App\Ekkon\Tasks\EkkonTask;
use App\Models\User;
use Intranet\Modules\Zeiterfassung\Models\Vormerkung;

/**
 * Bucht geplantes Gehen aus dem PC-Programm („Gehen in …") zur gewählten Zeit – auch wenn der
 * Rechner dann aus ist. Läuft das Programm, bucht meist schon dessen Status-Abruf; der Task
 * fängt den Rest. Doppelt gebucht wird nie (die Vormerkung wird vorher atomar geleert).
 */
class GeplantesGehen extends EkkonTask
{
    public string $category = 'Zeiterfassung';

    public string $description = 'Bucht im PC-Programm geplantes Gehen („Gehen in …") zur gewählten Zeit.';

    public function schedule(): string
    {
        return '* * * * *';
    }

    public function run(): array
    {
        $jetzt = now();
        $gebucht = 0;
        $ids = Vormerkung::whereNotNull('gehen_um')->where('gehen_um', '<=', $jetzt)->pluck('user_id');
        foreach (User::whereIn('id', $ids)->get() as $person) {
            if (Vormerkung::gehenBuchen($person, $jetzt)) {
                $gebucht++;
            }
        }
        if ($gebucht > 0) {
            $this->msg($gebucht.' geplantes Gehen gebucht.');
        }

        return ['faellig' => $ids->count(), 'gebucht' => $gebucht];
    }
}
