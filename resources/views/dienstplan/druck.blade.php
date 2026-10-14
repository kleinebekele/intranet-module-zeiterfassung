<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Dienstplan {{ $abteilung->name }} {{ $monat->isoFormat('MMMM YYYY') }}</title>
    <style>
        /* Papier: bewusst feste Farben, auch im dunklen Design. */
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: system-ui, sans-serif; color: #111827; background: #fff; margin: 16px; font-size: 10px; }
        h1 { font-size: 16px; margin: 0; }
        .kopf { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 2px 1px; text-align: center; vertical-align: top; }
        th { font-weight: normal; color: #374151; }
        th.name, td.name { width: 110px; text-align: left; padding-left: 4px; }
        th.summe, td.summe { width: 44px; text-align: right; padding-right: 4px; }
        .zu { background: #f3f4f6; color: #9ca3af; }
        .weg { color: #0369a1; }
        .hinweis { font-size: 7px; color: #6b7280; overflow: hidden; white-space: nowrap; }
        .woche { background: #f3f4f6; font-weight: 600; }
        .kw { text-align: left; font-weight: 600; border-left: 2px solid #9ca3af; }
        .neu { border-left: 2px solid #9ca3af; }
        .ue { font-size: 7px; font-weight: 700; color: #b45309; }
        .knopf { margin-bottom: 12px; }
        @media print { .knopf { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
<div class="knopf"><button onclick="window.print()">Drucken</button></div>
<div class="kopf">
    <div>
        <h1>Dienstplan {{ $abteilung->name }} – {{ $monat->isoFormat('MMMM YYYY') }}</h1>
        <div>{{ $plan->istVeroeffentlicht() ? 'Stand '.$plan->veroeffentlicht_am?->format('d.m.Y') : 'Entwurf' }}</div>
    </div>
    <div>Gedruckt {{ now()->format('d.m.Y H:i') }}</div>
</div>
<table>
    <thead>
        @php
            $dauer = fn ($m) => \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m);
            $kw = [];
            $start = null;
            foreach ($tage as $d => $t) {
                $start ??= $d;
                if (in_array($d, $wochenEnde, true)) {
                    $kw[] = ['nr' => $t['datum']->isoWeek, 'spalten' => count(array_filter(array_keys($tage), fn ($x) => $x >= $start && $x <= $d)) + 1];
                    $start = null;
                }
            }
        @endphp
        <tr>
            <th class="name"></th>
            @foreach ($kw as $w)
                <th class="kw" colspan="{{ $w['spalten'] }}">KW {{ $w['nr'] }}</th>
            @endforeach
            <th class="summe"></th>
        </tr>
        <tr>
            <th class="name">Person</th>
            @foreach ($tage as $d => $t)
                <th class="{{ $t['offen'] ? '' : 'zu' }} {{ $t['datum']->dayOfWeekIso === 1 || $loop->first ? 'neu' : '' }}">
                    {{ $t['datum']->isoFormat('dd') }}<br><strong>{{ $t['datum']->day }}</strong>
                    @if ($t['hinweis'])<div class="hinweis">{{ $t['hinweis'] }}</div>@endif
                </th>
                @if (in_array($d, $wochenEnde, true))<th class="woche">Σ</th>@endif
            @endforeach
            <th class="summe">Std.</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($personen as $uid => $p)
            <tr>
                <td class="name">{{ $p['user']->name }}</td>
                @foreach ($tage as $d => $t)
                    @php $s = $schichten[$uid][$d] ?? null; $weg = $p['abwesend'][$d] ?? null; @endphp
                    <td class="{{ $t['offen'] ? '' : 'zu' }} {{ $t['datum']->dayOfWeekIso === 1 || $loop->first ? 'neu' : '' }}">
                        @if ($s){{ $s->von }}<br>{{ $s->bis }}@elseif ($weg)<span class="weg">{{ mb_substr($weg, 0, 1) }}</span>@endif
                        @if ($ue = $pruefung['ueberstunden'][$uid][$d] ?? 0)<div class="ue">Ü +{{ $dauer($ue) }}</div>@endif
                    </td>
                    @if (in_array($d, $wochenEnde, true))
                        @php $w = $pruefung['wochen'][$uid][$d] ?? ['ist' => 0, 'soll' => 0]; @endphp
                        <td class="woche">{{ $dauer($w['ist']) }}@if (! $p['aushilfe'])<br><span style="font-weight: normal">/ {{ $dauer($w['soll']) }}</span>@endif</td>
                    @endif
                @endforeach
                <td class="summe">{{ $dauer($pruefung['personen'][$uid]['geplant']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>Zeiten = Anwesenheit inkl. Pause. Σ = Arbeitszeit der Woche / Soll (Feiertage und Abwesenheit gutgeschrieben). Ü = Überstunden. U = Urlaub, K = Krank, … · Grau = geschlossen.</p>
</body>
</html>
