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
        <tr>
            <th class="name">Person</th>
            @foreach ($tage as $d => $t)
                <th class="{{ $t['offen'] ? '' : 'zu' }}">
                    {{ $t['datum']->isoFormat('dd') }}<br><strong>{{ $t['datum']->day }}</strong>
                    @if ($t['hinweis'])<div class="hinweis">{{ $t['hinweis'] }}</div>@endif
                </th>
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
                    <td class="{{ $t['offen'] ? '' : 'zu' }}">
                        @if ($s){{ $s->von }}<br>{{ $s->bis }}@elseif ($weg)<span class="weg">{{ mb_substr($weg, 0, 1) }}</span>@endif
                    </td>
                @endforeach
                <td class="summe">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($pruefung['personen'][$uid]['geplant']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>Zeiten = Anwesenheit inkl. Pause. U = Urlaub, K = Krank, … · Grau = geschlossen.</p>
</body>
</html>
