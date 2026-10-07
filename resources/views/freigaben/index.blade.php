<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Freigaben</h1>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    <div class="space-y-6">
        <section>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Zeiten ({{ $buchungen->count() }})</h2>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Person</th>
                            <th class="px-4 py-3 font-semibold">Antrag</th>
                            <th class="px-4 py-3 font-semibold">Bisher</th>
                            <th class="px-4 py-3 font-semibold">Neu</th>
                            <th class="px-4 py-3 font-semibold">Begründung</th>
                            <th class="px-4 py-3 font-semibold">Entscheidung</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($buchungen as $b)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <a href="{{ route('module.zeiterfassung.team.person', ['person' => $b->user_id, 'monat' => $b->beginn->format('Y-m')]) }}" class="font-medium text-indigo-700 hover:underline">{{ $b->user->name }}</a>
                                    <span class="block text-xs text-gray-400">gestellt {{ $b->created_at->format('d.m. H:i') }}</span>
                                </td>
                                <td class="px-4 py-3">{{ $b->antragText() }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    @if ($b->ersetzt)
                                        {{ $b->ersetzt->beginn->format('d.m. H:i') }}–{{ $b->ersetzt->ende?->format('H:i') ?? '…' }}
                                    @else – @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    @if ($b->antrag === 'loeschen')
                                        <span class="text-red-600">löschen</span>
                                    @else
                                        {{ $b->beginn->isoFormat('dd DD.MM.') }} {{ $b->beginn->format('H:i') }}–{{ $b->ende?->format('H:i') ?? '…' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $b->notiz }}</td>
                                <td class="px-4 py-3">
                                    @include('zeiterfassung::freigaben.entscheiden', ['ziel' => route('module.zeiterfassung.freigaben.buchung', $b)])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Nichts offen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Urlaub & Abwesenheit ({{ $abwesenheiten->count() }})</h2>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Person</th>
                            <th class="px-4 py-3 font-semibold">Art</th>
                            <th class="px-4 py-3 font-semibold">Zeitraum</th>
                            <th class="px-4 py-3 font-semibold">Resturlaub</th>
                            <th class="px-4 py-3 font-semibold">Notiz</th>
                            <th class="px-4 py-3 font-semibold">Entscheidung</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($abwesenheiten as $a)
                            @php $u = \Intranet\Modules\Zeiterfassung\Support\Rechner::urlaub($a->user, $a->von->year); @endphp
                            <tr class="align-top">
                                <td class="px-4 py-3 font-medium">{{ $a->user->name }}</td>
                                <td class="px-4 py-3">{{ $a->artText() }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $a->von->format('d.m.Y') }}@if (! $a->von->eq($a->bis)) – {{ $a->bis->format('d.m.Y') }}@endif
                                    @if ($a->halbtag) (½) @endif
                                    <a href="{{ route('module.zeiterfassung.abwesenheiten.kalender', ['monat' => $a->von->format('Y-m')]) }}" class="ml-1 text-xs text-indigo-600 hover:underline">Kalender</a>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    @if ($a->istUrlaub())
                                        {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($u['rest'] - $u['geplant']) }} nach Genehmigung aller Anträge
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $a->notiz }}</td>
                                <td class="px-4 py-3">
                                    @include('zeiterfassung::freigaben.entscheiden', ['ziel' => route('module.zeiterfassung.freigaben.abwesenheit', $a)])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Nichts offen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($erledigt->isNotEmpty())
            <section>
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Zuletzt entschieden (14 Tage)</h2>
                <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white text-sm">
                    @foreach ($erledigt as $b)
                        <li class="px-4 py-2 text-gray-600">
                            {{ $b->user->name }}: {{ $b->antragText() }} {{ $b->beginn->format('d.m. H:i') }}–{{ $b->ende?->format('H:i') }}
                            – <strong class="{{ $b->status === 'abgelehnt' ? 'text-red-600' : 'text-green-700' }}">{{ $b->status === 'abgelehnt' ? 'abgelehnt' : 'freigegeben' }}</strong>
                            von {{ $b->entscheider?->name }} am {{ $b->entschieden_am->format('d.m. H:i') }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-app-layout>
