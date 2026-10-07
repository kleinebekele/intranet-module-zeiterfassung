<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Stundenzettel {{ $person->name }} {{ $monat->isoFormat('MMMM YYYY') }}</title>
    <style>
        /* Papier: bewusst feste Farben, auch im dunklen Design. */
        body { font-family: system-ui, sans-serif; color: #111827; background: #fff; margin: 24px; font-size: 12px; }
        h1 { font-size: 18px; margin: 0; }
        .kopf { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { font-size: 10px; text-transform: uppercase; color: #6b7280; }
        .r { text-align: right; }
        .frei { color: #9ca3af; }
        .warn { color: #b91c1c; font-size: 10px; }
        tfoot td { font-weight: bold; border-top: 2px solid #111827; }
        .unterschrift { display: flex; gap: 48px; margin-top: 48px; }
        .unterschrift div { flex: 1; border-top: 1px solid #111827; padding-top: 4px; color: #6b7280; }
        .knopf { margin-bottom: 16px; }
        @media print { .knopf { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
<div class="knopf"><button onclick="window.print()">Drucken</button></div>
<div class="kopf">
    <div>
        <h1>Stundenzettel {{ $monat->isoFormat('MMMM YYYY') }}</h1>
        <div>{{ $person->name }} · {{ $person->email }}</div>
    </div>
    <div class="r">
        Stundenkonto bis gestern: <strong>{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($kontostand, true) }}</strong><br>
        Resturlaub {{ $monat->year }}: <strong>{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['rest']) }} Tage</strong>
    </div>
</div>
<table>
    <thead>
        <tr><th>Tag</th><th>Beginn</th><th>Ende</th><th class="r">Pause</th><th class="r">Ist</th><th class="r">Soll</th><th class="r">Saldo</th><th>Bemerkung</th></tr>
    </thead>
    <tbody>
        @foreach ($tage as $t)
            <tr class="{{ $t->datum->dayOfWeekIso >= 6 || $t->feiertag ? 'frei' : '' }}">
                <td>{{ $t->datum->isoFormat('dd DD.MM.') }}</td>
                <td>{{ $t->beginn()?->format('H:i') }}</td>
                <td>{{ $t->ende()?->format('H:i') }}</td>
                <td class="r">{{ $t->abschnitte->isNotEmpty() ? $t->pauseGesamt().'′' : '' }}</td>
                <td class="r">{{ $t->abschnitte->isNotEmpty() ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->ist()) : '' }}</td>
                <td class="r">{{ $t->soll ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->soll) : '' }}</td>
                <td class="r">{{ $t->mitModell && ($t->soll || $t->abschnitte->isNotEmpty()) && $t->datum->lte(today()) ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->saldo(), true) : '' }}</td>
                <td>
                    {{ $t->feiertag }}
                    {{ $t->abwesenheit?->artText() }}{{ $t->abwesenheit?->halbtag ? ' (½)' : '' }}
                    @foreach ($t->warnungen as $w)<div class="warn">{{ $w }}</div>@endforeach
                </td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4">Summe</td>
            <td class="r">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['ist']) }}</td>
            <td class="r">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['soll']) }}</td>
            <td class="r">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['saldo'], true) }}</td>
            <td>{{ $summe['gutschrift'] ? 'davon Gutschrift '.\Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['gutschrift']).' (Urlaub/Krank)' : '' }}</td>
        </tr>
    </tfoot>
</table>
<div class="unterschrift">
    <div>Datum, Unterschrift Mitarbeiter/in</div>
    <div>Datum, Unterschrift Leitung</div>
</div>
</body>
</html>
