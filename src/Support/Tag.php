<?php

namespace Intranet\Modules\Zeiterfassung\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Intranet\Modules\Zeiterfassung\Models\Abwesenheit;
use Intranet\Modules\Zeiterfassung\Models\Buchung;

/** Ergebnis der Tagesrechnung einer Person – reine Daten für Ansicht, Export und Prüfung. */
class Tag
{
    /** @var Collection<int, Buchung> */
    public Collection $abschnitte;

    /** @var list<array{von: Carbon, bis: Carbon, minuten: int, zaehlt: bool}> */
    public array $pausen = [];

    /** @var array<string, string> regel => Text */
    public array $warnungen = [];

    public ?string $feiertag = null;

    public ?Abwesenheit $abwesenheit = null;

    /** Sollminuten laut Modell (0 an Feiertagen und ohne Modell). */
    public int $soll = 0;

    /** Summe der Arbeitsabschnitte. */
    public int $arbeit = 0;

    /** Pausen ab 15 Minuten (nur die zählen nach § 4 ArbZG). */
    public int $pause = 0;

    public int $pauseNoetig = 0;

    /** Automatisch abgezogene, fehlende Pause. */
    public int $abzug = 0;

    /** Gutschrift aus Urlaub, Krankheit … */
    public int $gutschrift = 0;

    public bool $offen = false;

    /** Gilt für die Person an diesem Tag ein Zeitmodell? Ohne Modell kein Saldo. */
    public bool $mitModell = false;

    public function __construct(public Carbon $datum)
    {
        $this->abschnitte = collect();
    }

    /** Anrechenbare Arbeitszeit. */
    public function ist(): int
    {
        return $this->arbeit - $this->abzug;
    }

    public function saldo(): int
    {
        // Zukünftige Tage zählen erst, wenn sie da sind – sonst stünde Mitte des Monats schon das ganze Soll im Minus.
        if (! $this->mitModell || $this->datum->isAfter(today())) {
            return 0;
        }

        return $this->ist() + $this->gutschrift - $this->soll;
    }

    public function beginn(): ?Carbon
    {
        return $this->abschnitte->first()?->beginn;
    }

    public function ende(): ?Carbon
    {
        return $this->abschnitte->last()?->ende;
    }

    /** Pausenminuten, die im Export als Pause erscheinen (Lücken + Abzug). */
    public function pauseGesamt(): int
    {
        return array_sum(array_column($this->pausen, 'minuten')) + $this->abzug;
    }

    public function istArbeitsfrei(): bool
    {
        return $this->soll === 0 && $this->abschnitte->isEmpty() && $this->abwesenheit === null;
    }
}
