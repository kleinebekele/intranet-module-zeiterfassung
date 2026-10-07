<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Team</h1>
            <a href="{{ route('module.zeiterfassung.abwesenheiten.kalender') }}" class="text-sm text-indigo-600 hover:underline">Abwesenheitskalender</a>
        </div>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    @if ($gruppen !== null)
        <p class="mb-3 text-sm text-gray-500">Du leitest: {{ $gruppen->map->name()->join(', ') ?: '–' }}</p>
    @endif

    @php
        $da = $zeilen->where('status.zustand', 'da')->count();
        $pause = $zeilen->where('status.zustand', 'pause')->count();
    @endphp
    <div class="mb-4 flex flex-wrap gap-3 text-sm">
        <span class="rounded-lg bg-green-50 px-3 py-1.5 text-green-800"><strong>{{ $da }}</strong> anwesend</span>
        <span class="rounded-lg bg-amber-50 px-3 py-1.5 text-amber-800"><strong>{{ $pause }}</strong> in der Pause</span>
        <span class="rounded-lg bg-sky-50 px-3 py-1.5 text-sky-800"><strong>{{ $zeilen->whereNotNull('abwesenheit')->count() }}</strong> abwesend</span>
        <span class="rounded-lg bg-gray-50 px-3 py-1.5 text-gray-600"><strong>{{ $zeilen->count() }}</strong> Personen</span>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">Person</th>
                    <th class="px-4 py-3 font-semibold">Jetzt</th>
                    <th class="px-4 py-3 text-right font-semibold">Heute</th>
                    <th class="px-4 py-3 text-right font-semibold">Stundenkonto</th>
                    <th class="px-4 py-3 font-semibold">Offen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($zeilen as $z)
                    @php $s = $z['status']; @endphp
                    <tr class="hover:bg-indigo-50/40">
                        <td class="px-4 py-3">
                            <a href="{{ route('module.zeiterfassung.team.person', $z['person']) }}" class="font-medium text-indigo-700 hover:underline">{{ $z['person']->name }}</a>
                        </td>
                        <td class="px-4 py-3">
                            @if ($z['abwesenheit'])
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800">{{ $z['abwesenheit']->artText() }}</span>
                            @elseif ($s['zustand'] === 'da')
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">da seit {{ $s['seit']->format($s['seit']->isToday() ? 'H:i' : 'd.m. H:i') }}</span>
                            @elseif ($s['zustand'] === 'pause')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Pause seit {{ $s['seit']->format('H:i') }}</span>
                            @else
                                <span class="text-xs text-gray-400">nicht da</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $s['heute'] ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($s['heute']) : '' }}</td>
                        <td class="px-4 py-3 text-right {{ ($z['konto'] ?? 0) < 0 ? 'text-red-600' : 'text-green-700' }}">
                            @if ($z['konto'] === null)
                                <span class="text-xs text-amber-700">keine Sollzeit</span>
                            @else
                                {{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($z['konto'], true) }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($z['antraege'])
                                <a href="{{ route('module.zeiterfassung.freigaben.index') }}" class="mr-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ $z['antraege'] }} Anträge</a>
                            @endif
                            @if ($z['verstoesse'])
                                <a href="{{ route('module.zeiterfassung.team.person', $z['person']) }}#verstoesse" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">{{ $z['verstoesse'] }} Verstöße</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                            Keine Personen. {{ $istVerwaltung ? 'Unter Einstellungen die Zeiterfassungs-Gruppen anlegen.' : 'Du leitest noch keine Gruppe.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
