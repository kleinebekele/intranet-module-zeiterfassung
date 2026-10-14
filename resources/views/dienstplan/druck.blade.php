<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Dienstplan {{ $abteilung->name }} {{ $monat->isoFormat('MMMM YYYY') }}</title>
    <style>
        /* Papier: bewusst feste Farben, auch im dunklen Design. */
        @page { size: A4 landscape; margin: 8mm; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: system-ui, sans-serif; color: #111827; background: #fff; margin: 16px; font-size: 10px; }
        h1 { font-size: 16px; margin: 0; }
        .kopf { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 6px; }
        .legende { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
        .legende span { padding: 1px 6px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 3px; vertical-align: top; }
        th { font-weight: 600; color: #374151; background: #f9fafb; }
        th.kw, td.kw { width: 34px; text-align: center; font-weight: 600; background: #f9fafb; }
        th.summe, td.summe { width: 92px; background: #f3f4f6; }
        td.tag { height: 62px; }
        td.zu { background: #f3f4f6; color: #9ca3af; }
        td.leer { background: #fafafa; border-color: #eeeeee; }
        .nr { font-weight: 700; font-size: 11px; }
        .hinweis { font-size: 8px; color: #b91c1c; }
        .schicht { display: flex; justify-content: space-between; gap: 3px; margin-top: 2px; padding: 1px 3px; border-radius: 3px; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .schicht b { font-weight: 700; }
        .weg { margin-top: 2px; font-size: 8px; color: #0369a1; white-space: nowrap; overflow: hidden; }
        .ue { color: #b45309; font-weight: 700; }
        .sum { display: flex; gap: 3px; white-space: nowrap; font-variant-numeric: tabular-nums; margin-top: 2px; }
        .sum .k { padding: 0 3px; border-radius: 3px; font-weight: 700; }
        .ueber { color: #b45309; font-weight: 700; }
        .unter { color: #b91c1c; font-weight: 700; }
        .grau { color: #6b7280; }
        .fuss { margin-top: 6px; color: #6b7280; }
        .knopf { margin-bottom: 12px; }
        @media print { .knopf { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
@php
    $dauer = fn ($m) => \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m);
    $zeit = fn (string $t) => preg_replace('/^0(\d)/', '$1', substr($t, 0, 5));
    $kurz = function (string $name): string {
        $teile = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $name))) ?: [$name];

        return count($teile) > 1
            ? mb_strtoupper(mb_substr($teile[0], 0, 1).mb_substr(end($teile), 0, 1))
            : mb_substr($teile[0], 0, 2);
    };
    // Dieselben Personenfarben wie in der Ansicht, als feste Papierfarben.
    $farben = ['#e0e7ff', '#d1fae5', '#ffe4e6', '#e0f2fe', '#ede9fe', '#ccfbf1', '#ffedd5', '#ecfccb'];
    $farbe = [];
    foreach (array_keys($personen) as $nr => $uid) {
        $farbe[$uid] = $farben[$nr % count($farben)];
    }
@endphp
<div class="knopf"><button onclick="window.print()">Drucken</button></div>
<div class="kopf">
    <div>
        <h1>Dienstplan {{ $abteilung->name }} – {{ $monat->isoFormat('MMMM YYYY') }}</h1>
        <div>{{ $plan->istVeroeffentlicht() ? 'Stand '.$plan->veroeffentlicht_am?->format('d.m.Y') : 'Entwurf' }}</div>
    </div>
    <div>Gedruckt {{ now()->format('d.m.Y H:i') }}</div>
</div>
<div class="legende">
    @foreach ($personen as $uid => $p)
        @php $pr = $pruefung['personen'][$uid]; @endphp
        <span style="background: {{ $farbe[$uid] }}"><b>{{ $kurz($p['user']->name) }}</b> {{ $p['user']->name }} · {{ $dauer($pr['geplant']) }}{{ $p['aushilfe'] ? '' : ' / '.$dauer($pr['ziel']) }}</span>
    @endforeach
</div>
<table>
    <thead>
        <tr>
            <th class="kw">KW</th>
            @foreach (\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE as $name)
                <th>{{ $name }}</th>
            @endforeach
            <th class="summe">Summe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($wochen as $woche)
            @php $erster = $tage[$woche[0]]['datum']; $ende = end($woche); @endphp
            <tr>
                <td class="kw">{{ $erster->isoWeek }}</td>
                @for ($leer = 1; $leer < $erster->dayOfWeekIso; $leer++)
                    <td class="leer"></td>
                @endfor
                @foreach ($woche as $d)
                    @php $t = $tage[$d]; @endphp
                    <td class="tag {{ $t['offen'] ? '' : 'zu' }}">
                        <span class="nr">{{ $t['datum']->day }}.</span>
                        @if ($t['hinweis'])<div class="hinweis">{{ $t['hinweis'] }}</div>@endif
                        @foreach ($personen as $uid => $p)
                            @if ($s = $schichten[$uid][$d] ?? null)
                                <div class="schicht" style="background: {{ $farbe[$uid] }}">
                                    <b>{{ $kurz($p['user']->name) }}</b>
                                    <span>{{ $zeit($s->von) }}–{{ $zeit($s->bis) }}@if ($pruefung['ueberstunden'][$uid][$d] ?? 0) <span class="ue">Ü</span>@endif</span>
                                </div>
                            @elseif ($weg = $p['abwesend'][$d] ?? null)
                                <div class="weg">{{ $kurz($p['user']->name) }} {{ $weg }}</div>
                            @endif
                        @endforeach
                    </td>
                @endforeach
                @for ($leer = $tage[$ende]['datum']->dayOfWeekIso; $leer < 7; $leer++)
                    <td class="leer"></td>
                @endfor
                <td class="summe">
                    @foreach ($personen as $uid => $p)
                        @php $w = $pruefung['wochen'][$uid][$ende] ?? ['ist' => 0, 'soll' => 0]; $diff = $w['ist'] - $w['soll']; @endphp
                        @if ($w['ist'] || $w['soll'])
                            <div class="sum">
                                <span class="k" style="background: {{ $farbe[$uid] }}">{{ $kurz($p['user']->name) }}</span>
                                <span class="{{ $p['aushilfe'] || abs($diff) < 15 ? '' : ($diff > 0 ? 'ueber' : 'unter') }}">{{ $dauer($w['ist']) }}</span>
                                @if (! $p['aushilfe'])<span class="grau">/ {{ $dauer($w['soll']) }}</span>@endif
                            </div>
                        @endif
                    @endforeach
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
<p class="fuss">Zeiten = Anwesenheit inkl. Pause. Summe = Arbeitszeit der Woche / Soll (Feiertage und Abwesenheit gutgeschrieben), <span class="ueber">über</span> bzw. <span class="unter">unter</span> Soll markiert. Ü = Überstunden. Grau = geschlossen.</p>
</body>
</html>
