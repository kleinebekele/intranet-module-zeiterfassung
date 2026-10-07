<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Buchung;
use RuntimeException;

/**
 * Nachträge, Änderungen und Löschungen von Buchungen – direkt oder als Antrag –
 * und die Entscheidung über Anträge. Nichts wird überschrieben: jede Änderung
 * ist eine neue Zeile, die alte bekommt „ersetzt" bzw. „geloescht".
 */
class Korrektur
{
    /**
     * @param  bool  $direkt  true = sofort gültig, false = Antrag
     * @param  string  $quelle  nachtrag (eigene) | leitung (für andere)
     */
    public static function speichern(User $person, User $wer, ?Buchung $alt, Carbon $beginn, ?Carbon $ende, ?string $notiz, bool $direkt, string $quelle): Buchung
    {
        if ($ende && $ende->lte($beginn)) {
            throw new RuntimeException('Das Ende muss nach dem Beginn liegen.');
        }
        if ($ende && $beginn->diffInHours($ende) > 24) {
            throw new RuntimeException('Ein Abschnitt darf nicht länger als 24 Stunden sein.');
        }
        if ($beginn->gt(now()) || ($ende && $ende->gt(now()->addMinute()))) {
            throw new RuntimeException('Zeiten in der Zukunft lassen sich nicht erfassen.');
        }
        if ($alt && $alt->status !== Buchung::GUELTIG) {
            throw new RuntimeException('Diese Buchung wurde inzwischen geändert.');
        }
        if (! $direkt && ! $notiz) {
            throw new RuntimeException('Bitte eine Begründung angeben.');
        }

        self::pruefeUeberschneidung($person, $beginn, $ende, $alt);

        return DB::transaction(function () use ($person, $wer, $alt, $beginn, $ende, $notiz, $direkt, $quelle) {
            $neu = Buchung::create([
                'user_id' => $person->id,
                'beginn' => $beginn,
                'ende' => $ende,
                'ende_art' => $ende ? ($alt?->ende_art ?? 'gehen') : null,
                'quelle' => $quelle,
                'status' => $direkt ? Buchung::GUELTIG : Buchung::BEANTRAGT,
                'antrag' => $alt ? 'aendern' : 'neu',
                'ersetzt_id' => $alt?->id,
                'terminal_id' => $alt?->terminal_id,
                'notiz' => $notiz,
                'erfasst_von' => $wer->id,
                'entschieden_von' => $direkt ? $wer->id : null,
                'entschieden_am' => $direkt ? now() : null,
            ]);

            if ($direkt && $alt) {
                $alt->update(['status' => Buchung::ERSETZT]);
            }

            self::audit($direkt ? 'zeiterfassung.buchung_geaendert' : 'zeiterfassung.antrag_gestellt',
                ($alt ? 'Buchung geändert' : 'Buchung nachgetragen').' für '.$beginn->format('d.m.Y'), $person);

            return $neu;
        });
    }

    public static function loeschen(User $person, User $wer, Buchung $alt, ?string $notiz, bool $direkt): void
    {
        if ($alt->status !== Buchung::GUELTIG) {
            throw new RuntimeException('Diese Buchung wurde inzwischen geändert.');
        }
        if (! $direkt && ! $notiz) {
            throw new RuntimeException('Bitte eine Begründung angeben.');
        }

        DB::transaction(function () use ($person, $wer, $alt, $notiz, $direkt) {
            if ($direkt) {
                $alt->update(['status' => Buchung::GELOESCHT, 'entschieden_von' => $wer->id, 'entschieden_am' => now(),
                    'entscheid_notiz' => $notiz]);
            } else {
                Buchung::create([
                    'user_id' => $person->id,
                    'beginn' => $alt->beginn,
                    'ende' => $alt->ende,
                    'ende_art' => $alt->ende_art,
                    'quelle' => 'nachtrag',
                    'status' => Buchung::BEANTRAGT,
                    'antrag' => 'loeschen',
                    'ersetzt_id' => $alt->id,
                    'notiz' => $notiz,
                    'erfasst_von' => $wer->id,
                ]);
            }

            self::audit($direkt ? 'zeiterfassung.buchung_geloescht' : 'zeiterfassung.antrag_gestellt',
                'Buchung vom '.$alt->beginn->format('d.m.Y H:i').($direkt ? ' gelöscht' : ': Löschung beantragt'), $person);
        });
    }

    public static function entscheiden(Buchung $antrag, User $wer, bool $ja, ?string $notiz): void
    {
        if ($antrag->status !== Buchung::BEANTRAGT) {
            throw new RuntimeException('Über diesen Antrag wurde schon entschieden.');
        }

        DB::transaction(function () use ($antrag, $wer, $ja, $notiz) {
            $alt = $antrag->ersetzt;
            if ($ja && $alt && $alt->status !== Buchung::GUELTIG) {
                throw new RuntimeException('Die ursprüngliche Buchung wurde inzwischen geändert – Antrag bitte ablehnen.');
            }

            $entscheid = ['entschieden_von' => $wer->id, 'entschieden_am' => now(), 'entscheid_notiz' => $notiz];

            if (! $ja) {
                $antrag->update($entscheid + ['status' => Buchung::ABGELEHNT]);
            } elseif ($antrag->antrag === 'loeschen') {
                $antrag->update($entscheid + ['status' => Buchung::ERLEDIGT]);
                $alt->update(['status' => Buchung::GELOESCHT]);
            } else {
                self::pruefeUeberschneidung($antrag->user, $antrag->beginn, $antrag->ende, $alt);
                $antrag->update($entscheid + ['status' => Buchung::GUELTIG]);
                $alt?->update(['status' => Buchung::ERSETZT]);
            }

            self::audit($ja ? 'zeiterfassung.antrag_genehmigt' : 'zeiterfassung.antrag_abgelehnt',
                $antrag->antragText().' vom '.$antrag->beginn->format('d.m.Y'), $antrag->user);
        });
    }

    public static function abwesenheitEntscheiden(Abwesenheit $a, User $wer, bool $ja, ?string $notiz): void
    {
        if ($a->status !== Abwesenheit::BEANTRAGT) {
            throw new RuntimeException('Über diesen Antrag wurde schon entschieden.');
        }

        $a->update([
            'status' => $ja ? Abwesenheit::GENEHMIGT : Abwesenheit::ABGELEHNT,
            'entschieden_von' => $wer->id,
            'entschieden_am' => now(),
            'entscheid_notiz' => $notiz,
        ]);

        self::audit($ja ? 'zeiterfassung.antrag_genehmigt' : 'zeiterfassung.antrag_abgelehnt',
            $a->artText().' '.$a->von->format('d.m.').'–'.$a->bis->format('d.m.Y'), $a->user);
    }

    private static function pruefeUeberschneidung(User $person, Carbon $beginn, ?Carbon $ende, ?Buchung $alt): void
    {
        $ende ??= now();
        $kollision = Buchung::gueltig()->where('user_id', $person->id)
            ->when($alt, fn ($q) => $q->where('id', '!=', $alt->id))
            ->where('beginn', '<', $ende)
            ->where(fn ($q) => $q->whereNull('ende')->orWhere('ende', '>', $beginn))
            ->first();

        if ($kollision) {
            throw new RuntimeException('Überschneidet sich mit der Buchung '.$kollision->beginn->format('d.m. H:i').'–'
                .($kollision->ende?->format('H:i') ?? 'offen').'.');
        }
    }

    public static function audit(string $aktion, string $text, ?User $betroffener): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $text, betroffener: $betroffener);
        }
    }
}
