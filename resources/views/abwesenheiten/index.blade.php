<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Urlaub & Abwesenheit</h1>
            <a href="{{ route('module.zeiterfassung.abwesenheiten.kalender') }}" class="text-sm text-indigo-600 hover:underline">Kalender: wer ist wann weg</a>
        </div>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-800">Urlaubskonto {{ $jahr }}</h2>
                    <div class="flex gap-1 text-sm">
                        <a href="?jahr={{ $jahr - 1 }}" class="rounded px-2 text-gray-500 hover:bg-gray-100">‹</a>
                        <a href="?jahr={{ $jahr + 1 }}" class="rounded px-2 text-gray-500 hover:bg-gray-100">›</a>
                    </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500">Anspruch</dt><dd class="text-right">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['anspruch']) }}</dd>
                    <dt class="text-gray-500">Übertrag Vorjahr</dt><dd class="text-right">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['uebertrag']) }}</dd>
                    <dt class="text-gray-500">Genommen / genehmigt</dt><dd class="text-right">− {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['genommen']) }}</dd>
                    <dt class="border-t border-gray-100 pt-1 font-semibold text-gray-800">Rest</dt>
                    <dd class="border-t border-gray-100 pt-1 text-right font-semibold">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['rest']) }}</dd>
                    @if ($urlaub['geplant'] > 0)
                        <dt class="text-amber-700">davon beantragt</dt><dd class="text-right text-amber-700">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['geplant']) }}</dd>
                    @endif
                </dl>
            </div>

            @if ($teilnehmer)
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-gray-800">Neu eintragen</h2>
                    <form method="POST" action="{{ route('module.zeiterfassung.abwesenheiten.beantragen') }}" class="mt-3 space-y-3"
                          x-data="{ von: '{{ old('von') }}', bis: '{{ old('bis') }}', art: '{{ old('art', 'urlaub') }}' }">
                        @csrf
                        @include('zeiterfassung::partials.abwesenheit-felder')
                        <p class="text-xs text-gray-500" x-show="art === 'krank' || art === 'kind_krank'">
                            Krankmeldungen gelten sofort. Die Arbeitsunfähigkeitsbescheinigung bitte wie gewohnt einreichen.
                        </p>
                        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                                x-text="(art === 'krank' || art === 'kind_krank') ? 'Krank melden' : 'Beantragen'">Beantragen</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="xl:col-span-2">
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Art</th>
                            <th class="px-4 py-3 font-semibold">Zeitraum</th>
                            <th class="px-4 py-3 text-right font-semibold">Arbeitstage</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            <th class="px-4 py-3 font-semibold">Notiz</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($liste as $a)
                            <tr class="{{ in_array($a->status, [\Intranet\Modules\Zeiterfassung\Models\Abwesenheit::ABGELEHNT, \Intranet\Modules\Zeiterfassung\Models\Abwesenheit::STORNIERT]) ? 'text-gray-400' : '' }}">
                                <td class="px-4 py-3 font-medium">{{ $a->artText() }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $a->von->format('d.m.Y') }}@if (! $a->von->eq($a->bis)) – {{ $a->bis->format('d.m.Y') }}@endif
                                    @if ($a->halbtag) <span class="text-gray-500">(½ Tag)</span> @endif
                                </td>
                                <td class="px-4 py-3 text-right">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage(\Intranet\Modules\Zeiterfassung\Support\Rechner::arbeitstage($a, $modelle)) }}</td>
                                <td class="px-4 py-3">
                                    @include('zeiterfassung::partials.abwesenheit-status', ['a' => $a])
                                </td>
                                <td class="px-4 py-3 text-gray-500">
                                    {{ $a->notiz }}
                                    @if ($a->entscheid_notiz)
                                        <span class="block text-xs">Leitung: {{ $a->entscheid_notiz }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($a->status === \Intranet\Modules\Zeiterfassung\Models\Abwesenheit::BEANTRAGT || ($a->status === \Intranet\Modules\Zeiterfassung\Models\Abwesenheit::GENEHMIGT && $a->von->isFuture()))
                                        <form method="POST" action="{{ route('module.zeiterfassung.abwesenheiten.stornieren', $a) }}"
                                              data-bestaetigen="{{ $a->artText() }} {{ $a->von->format('d.m.') }}–{{ $a->bis->format('d.m.Y') }} zurückziehen?" data-knopf="Zurückziehen">
                                            @csrf
                                            <button type="submit" class="text-sm text-red-600 hover:underline">Zurückziehen</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Keine Einträge in {{ $jahr }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
