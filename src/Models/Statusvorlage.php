<?php

namespace Intranet\Modules\Zeiterfassung\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eigene Teams-Statusvorlage: Status + Text für eine Dauer, danach automatisch zurück auf
 * online oder erst nach Rückfrage im PC-Programm; optional zugleich eine Pause buchen.
 */
class Statusvorlage extends Model
{
    protected $table = 'zeit_statusvorlagen';

    protected $guarded = ['id'];

    /** Teams-Verfügbarkeit => [Bezeichnung, Aktivität für setUserPreferredPresence] */
    public const STATUS = [
        'DoNotDisturb' => ['Nicht stören', 'DoNotDisturb'],
        'Busy' => ['Beschäftigt', 'Busy'],
        'Away' => ['Abwesend', 'Away'],
        'BeRightBack' => ['Bin gleich zurück', 'BeRightBack'],
        'Offline' => ['Offline', 'OffWork'],
    ];

    public const RUECKKEHR = [
        'frage' => 'nach Rückfrage',
        'automatisch' => 'automatisch',
    ];

    protected function casts(): array
    {
        return ['pause' => 'boolean', 'minuten' => 'integer'];
    }

    public function statusText(): string
    {
        return self::STATUS[$this->verfuegbarkeit][0] ?? $this->verfuegbarkeit;
    }

    public function aktivitaet(): string
    {
        return self::STATUS[$this->verfuegbarkeit][1] ?? $this->verfuegbarkeit;
    }

    /** Für das PC-Programm. */
    public function fuerApp(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'minuten' => $this->minuten,
            'status' => $this->statusText(),
            'text' => $this->text,
            'automatisch' => $this->rueckkehr === 'automatisch',
            'pause' => $this->pause,
        ];
    }
}
