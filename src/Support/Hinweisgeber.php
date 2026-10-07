<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\User;
use App\Support\Hinweis;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use Intranet\Modules\Zeiterfassung\Models\Verstoss;

/**
 * Was die Glocke in der Kopfzeile aus der Zeiterfassung meldet. Läuft bei jedem
 * Seitenaufruf – deshalb nur wenige Zählabfragen.
 */
class Hinweisgeber
{
    /** @return list<Hinweis> */
    public static function fuer(User $user): array
    {
        $hinweise = [];

        if (Rechte::istTeilnehmer($user) && ($offen = Stempeluhr::offen($user))) {
            if ($offen->beginn->lt(today())) {
                $hinweise[] = new Hinweis('Seit '.$offen->beginn->format('d.m. H:i').' eingestempelt – Gehen vergessen?',
                    route('module.zeiterfassung.meine.index'), 'Zeiterfassung');
            } else {
                $block = Stempeluhr::status($user)['block'];
                $max = Einstellungen::int('max_block');
                if ($block >= $max - 15) {
                    $hinweise[] = new Hinweis('Pause fällig: '.Format::dauer($block).' Std. ohne Pause',
                        route('module.zeiterfassung.meine.index'), 'Zeiterfassung');
                }
            }
        }

        if (Rechte::darfTeam($user)) {
            $ids = Rechte::verwalteteIds($user)->reject(fn ($id) => $id === $user->id);
            if ($ids->isNotEmpty()) {
                $antraege = Buchung::where('status', Buchung::BEANTRAGT)->whereIn('user_id', $ids)->count()
                    + Abwesenheit::where('status', Abwesenheit::BEANTRAGT)->whereIn('user_id', $ids)->count();
                if ($antraege > 0) {
                    $hinweise[] = new Hinweis("{$antraege} Zeit-Anträge warten auf Freigabe",
                        route('module.zeiterfassung.freigaben.index'), 'Zeiterfassung', $antraege);
                }

                $verstoesse = Verstoss::whereNull('gesehen_am')->whereIn('user_id', $ids)->count();
                if ($verstoesse > 0) {
                    $hinweise[] = new Hinweis("{$verstoesse} Verstöße gegen das Arbeitszeitgesetz",
                        route('module.zeiterfassung.team.index'), 'Zeiterfassung', $verstoesse);
                }
            }
        }

        return $hinweise;
    }
}
