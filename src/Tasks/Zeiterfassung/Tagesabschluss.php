<?php

namespace Intranet\Modules\Zeiterfassung\Tasks\Zeiterfassung;

use App\Ekkon\Tasks\EkkonTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Models\Verstoss;
use Intranet\Modules\Zeiterfassung\Support\Rechner;
use Intranet\Modules\Zeiterfassung\Support\Rechte;

/**
 * Prüft nachts die vergangenen Tage aller Teilnehmer gegen das
 * Arbeitszeitgesetz und hält Verstöße fest (Glocke der Leitung, Team-Seite).
 *
 * Geprüft werden die letzten 7 Tage: Nachträge und Korrekturen ändern ja auch
 * Vergangenes. Ein Verstoß, der durch eine Korrektur verschwindet, wird
 * entfernt – solange ihn noch niemand als gesehen markiert hat.
 */
class Tagesabschluss extends EkkonTask
{
    public string $category = 'Zeiterfassung';

    public string $description = 'Prüft die letzten Tage aller Teilnehmer auf Verstöße gegen das Arbeitszeitgesetz '
        .'(Pausen, Höchstarbeitszeit, Ruhezeit, nicht ausgestempelt).';

    public array $meldungsarten = [
        'zeit-verstoss' => 'Zeiterfassung: neue Verstöße gegen das Arbeitszeitgesetz',
    ];

    public array $einstellungen = [
        'tage' => [
            'typ' => 'text',
            'label' => 'Wie viele vergangene Tage prüfen',
            'standard' => '7',
            'hilfe' => 'Nachträge ändern auch ältere Tage; die Prüfung holt sie damit nach.',
        ],
    ];

    public function schedule(): string
    {
        return '20 0 * * *';
    }

    public function run(): array
    {
        $tage = max(1, min(60, (int) $this->einstellung('tage')));
        $bis = today()->subDay();
        $von = $bis->copy()->subDays($tage - 1);

        $neu = [];
        $entfernt = 0;
        $personen = User::whereIn('id', Rechte::teilnehmerIds())->get();

        foreach ($personen as $person) {
            $rechner = new Rechner($person, $von, $bis);
            foreach ($rechner->tage() as $tag) {
                $vorhanden = Verstoss::where('user_id', $person->id)->whereDate('datum', $tag->datum->toDateString())->get()->keyBy('regel');

                foreach ($tag->warnungen as $regel => $text) {
                    if ($v = $vorhanden->get($regel)) {
                        if ($v->text !== $text && $v->gesehen_am === null) {
                            $v->update(['text' => $text]);
                        }

                        continue;
                    }
                    Verstoss::create(['user_id' => $person->id, 'datum' => $tag->datum->toDateString(), 'regel' => $regel, 'text' => $text]);
                    $neu[] = $person->name.', '.$tag->datum->format('d.m.').': '.$text;
                }

                foreach ($vorhanden as $regel => $v) {
                    if (! isset($tag->warnungen[$regel]) && $v->gesehen_am === null) {
                        $v->delete();
                        $entfernt++;
                    }
                }
            }
        }

        $this->msg(count($personen).' Personen geprüft ('.$von->format('d.m.').'–'.$bis->format('d.m.')."), "
            .count($neu).' neue Verstöße, '.$entfernt.' durch Korrektur erledigt.');
        $this->debug['neu'] = array_slice($neu, 0, 50);

        if ($neu !== []) {
            $this->benachrichtige('zeit-verstoss', count($neu).' neue Verstöße gegen das Arbeitszeitgesetz',
                implode("\n", array_slice($neu, 0, 30)).(count($neu) > 30 ? "\n…" : ''),
                idempotenzSchluessel: 'zeit-verstoss-'.Carbon::today()->toDateString());
        }

        return ['personen' => count($personen), 'neu' => count($neu), 'entfernt' => $entfernt];
    }
}
