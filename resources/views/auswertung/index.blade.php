<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Auswertung & Export</h1>
    </x-slot>

    @php
        $m = $monat->format('Y-m');
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div class="flex items-center gap-2">
                <a href="?monat={{ $monat->copy()->subMonth()->format('Y-m') }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">‹</a>
                <h2 class="min-w-36 text-center text-base font-semibold text-gray-800">{{ $monat->isoFormat('MMMM YYYY') }}</h2>
                <a href="?monat={{ $monat->copy()->addMonth()->format('Y-m') }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">›</a>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('module.zeiterfassung.auswertung.csv', ['monat' => $m, 'art' => 'personio']) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 font-medium text-white hover:bg-indigo-700"
                   title="Je Arbeitsabschnitt und Pause eine Zeile, Person per E-Mail – passend zum Anwesenheiten-Import in Personio">
                    <x-module-icon name="download" class="text-base" /> CSV für Personio
                </a>
                <a href="{{ route('module.zeiterfassung.auswertung.csv', ['monat' => $m, 'art' => 'tage']) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-gray-700 hover:bg-gray-50">
                    <x-module-icon name="download" class="text-base" /> Tagessummen
                </a>
                <a href="{{ route('module.zeiterfassung.auswertung.csv', ['monat' => $m, 'art' => 'abwesenheiten']) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-gray-700 hover:bg-gray-50">
                    <x-module-icon name="download" class="text-base" /> Abwesenheiten
                </a>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Person</th>
                        <th class="px-4 py-3 text-right font-semibold">Soll</th>
                        <th class="px-4 py-3 text-right font-semibold">Gearbeitet</th>
                        <th class="px-4 py-3 text-right font-semibold">Urlaub/Krank</th>
                        <th class="px-4 py-3 text-right font-semibold">Saldo Monat</th>
                        <th class="px-4 py-3 text-right font-semibold">Hinweise</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($zeilen as $z)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('module.zeiterfassung.team.person', ['person' => $z['person'], 'monat' => $m]) }}" class="font-medium text-indigo-700 hover:underline">{{ $z['person']->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-600">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($z['summe']['soll']) }}</td>
                            <td class="px-4 py-3 text-right">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($z['summe']['ist']) }}</td>
                            <td class="px-4 py-3 text-right text-sky-700">{{ $z['summe']['gutschrift'] ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($z['summe']['gutschrift']) : '' }}</td>
                            <td class="px-4 py-3 text-right font-medium {{ $z['summe']['saldo'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($z['summe']['saldo'], true) }}</td>
                            <td class="px-4 py-3 text-right {{ $z['warnungen'] ? 'text-red-600' : 'text-gray-300' }}">{{ $z['warnungen'] }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('module.zeiterfassung.auswertung.stundenzettel', ['person' => $z['person']->id, 'monat' => $m]) }}" target="_blank" class="text-xs text-indigo-600 hover:underline">Stundenzettel</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Keine Personen.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500">
            Personio: Einstellungen → Organisation → Importe → „Anwesenheiten". Die CSV hat je Arbeitsabschnitt und je Pause eine Zeile,
            die Person wird über die E-Mail-Adresse erkannt. Die Spalten bitte beim ersten Import einmal mit der Personio-Vorlage abgleichen.
        </p>
    </div>
</x-app-layout>
