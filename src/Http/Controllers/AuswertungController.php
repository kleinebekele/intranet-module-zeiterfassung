<?php

namespace Intranet\Modules\Zeiterfassung\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Support\Format;
use Intranet\Modules\Zeiterfassung\Support\Rechner;
use Intranet\Modules\Zeiterfassung\Support\Rechte;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Monatsübersicht aller verwalteten Personen, CSV-Export und Stundenzettel.
 *
 * CSV-Arten:
 *  - personio:  je Arbeitsabschnitt und je Pause eine Zeile, Person per E-Mail –
 *               so erwartet es der Anwesenheiten-Import von Personio.
 *  - tage:      je Person und Tag eine Zeile mit Soll, Ist, Pause, Saldo.
 *  - abwesenheiten: genehmigte Abwesenheiten des Monats.
 */
class AuswertungController
{
    use Monatsdaten;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(Rechte::darfTeam($user), 403);
        $monat = $this->monat($request);

        $zeilen = $this->personen($user)->map(function (User $p) use ($monat) {
            $tage = (new Rechner($p, $monat, $monat->copy()->endOfMonth()))->tage();
            $warnungen = array_sum(array_map(fn ($t) => count($t->warnungen), $tage));

            return ['person' => $p, 'summe' => Rechner::summe($tage), 'warnungen' => $warnungen];
        });

        return view('zeiterfassung::auswertung.index', compact('monat', 'zeilen'));
    }

    public function csv(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless(Rechte::darfTeam($user), 403);
        $monat = $this->monat($request);
        $art = in_array($request->query('art'), ['personio', 'tage', 'abwesenheiten'], true) ? $request->query('art') : 'personio';
        $personen = $this->personen($user);

        $datei = "zeiterfassung-{$art}-{$monat->format('Y-m')}.csv";

        return response()->streamDownload(function () use ($art, $personen, $monat) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel UTF-8 erkennt
            $bis = $monat->copy()->endOfMonth();

            if ($art === 'abwesenheiten') {
                fputcsv($out, ['E-Mail', 'Name', 'Art', 'Von', 'Bis', 'Halber Tag', 'Notiz'], ';');
                $liste = Abwesenheit::with('user')->genehmigt()->whereIn('user_id', $personen->pluck('id'))
                    ->ueberschneidet($monat, $bis)->orderBy('von')->get();
                foreach ($liste as $a) {
                    fputcsv($out, [$a->user->email, $a->user->name, $a->artText(), $a->von->format('Y-m-d'),
                        $a->bis->format('Y-m-d'), $a->halbtag ? 'ja' : 'nein', $a->notiz], ';');
                }
                fclose($out);

                return;
            }

            fputcsv($out, $art === 'personio'
                ? ['E-Mail', 'Datum', 'Beginn', 'Ende', 'Art', 'Kommentar']
                : ['E-Mail', 'Name', 'Datum', 'Beginn', 'Ende', 'Pause (Min.)', 'Abzug Pause (Min.)', 'Ist', 'Soll', 'Saldo', 'Abwesenheit', 'Feiertag', 'Hinweise'], ';');

            foreach ($personen as $p) {
                foreach ((new Rechner($p, $monat, $bis))->tage() as $t) {
                    if ($art === 'tage') {
                        if ($t->istArbeitsfrei() && ! $t->feiertag) {
                            continue;
                        }
                        fputcsv($out, [$p->email, $p->name, $t->datum->format('Y-m-d'), $t->beginn()?->format('H:i'),
                            $t->ende()?->format('H:i'), array_sum(array_column($t->pausen, 'minuten')), $t->abzug,
                            Format::dauer($t->ist()), Format::dauer($t->soll), Format::dauer($t->saldo()),
                            $t->abwesenheit?->artText(), $t->feiertag, implode(' | ', $t->warnungen)], ';');

                        continue;
                    }

                    // Personio: Arbeit und Pausen als eigene Zeilen; offene Abschnitte fehlen (noch kein Ende).
                    $kommentar = $t->abzug > 0 ? "Gesetzliche Pause fehlte, {$t->abzug} Min. abgezogen" : '';
                    $vorher = null;
                    foreach ($t->abschnitte as $b) {
                        if (! $b->ende) {
                            continue;
                        }
                        if ($vorher && $b->beginn->gt($vorher->ende)) {
                            fputcsv($out, [$p->email, $t->datum->format('Y-m-d'), $vorher->ende->format('H:i'), $b->beginn->format('H:i'), 'Pause', ''], ';');
                        }
                        fputcsv($out, [$p->email, $t->datum->format('Y-m-d'), $b->beginn->format('H:i'), $b->ende->format('H:i'), 'Arbeit', $kommentar], ';');
                        $kommentar = '';
                        $vorher = $b;
                    }
                }
            }
            fclose($out);
        }, $datei, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Druckbarer Stundenzettel einer Person für einen Monat. */
    public function stundenzettel(Request $request)
    {
        $user = $request->user();
        $person = User::findOrFail($request->integer('person') ?: $user->id);
        abort_unless($person->id === $user->id || Rechte::darfVerwalten($user, $person->id), 403);

        return view('zeiterfassung::auswertung.stundenzettel', $this->monatsdaten($person, $this->monat($request)));
    }

    private function personen(User $user)
    {
        return User::whereIn('id', Rechte::verwalteteIds($user))->orderBy('name')->get();
    }
}
