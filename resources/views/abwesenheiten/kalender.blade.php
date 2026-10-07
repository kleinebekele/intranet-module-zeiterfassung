<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Abwesenheitskalender</h1>
            <a href="{{ route('module.zeiterfassung.abwesenheiten.index') }}" class="text-sm text-indigo-600 hover:underline">zurück</a>
        </div>
    </x-slot>

    @php
        $tage = [];
        for ($d = $monat->copy(); $d->lte($monat->copy()->endOfMonth()); $d->addDay()) {
            $tage[] = $d->copy();
        }
        $land = \Intranet\Modules\Zeiterfassung\Support\Einstellungen::get('bundesland');
    @endphp

    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="flex items-center gap-2 border-b border-gray-200 px-4 py-3">
            <a href="?monat={{ $monat->copy()->subMonth()->format('Y-m') }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">‹</a>
            <h2 class="min-w-36 text-center text-base font-semibold text-gray-800">{{ $monat->isoFormat('MMMM YYYY') }}</h2>
            <a href="?monat={{ $monat->copy()->addMonth()->format('Y-m') }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">›</a>
            <span class="ml-4 flex items-center gap-3 text-xs text-gray-500">
                <span class="inline-block h-3 w-3 rounded bg-sky-400"></span> genehmigt
                <span class="inline-block h-3 w-3 rounded bg-amber-300"></span> beantragt
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-xs">
                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50 text-gray-500">
                        <th class="sticky left-0 bg-gray-50 px-3 py-2 text-left font-semibold">Person</th>
                        @foreach ($tage as $t)
                            @php $frei = $t->dayOfWeekIso >= 6 || \Intranet\Modules\Zeiterfassung\Support\Feiertage::name($t, $land); @endphp
                            <th class="w-7 px-0.5 py-2 text-center font-normal {{ $frei ? 'text-gray-300' : '' }} {{ $t->isToday() ? 'text-indigo-600 font-bold' : '' }}">
                                {{ $t->isoFormat('dd') }}<br>{{ $t->day }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($personen as $p)
                        <tr>
                            <td class="sticky left-0 whitespace-nowrap bg-white px-3 py-1.5 text-sm text-gray-800">{{ $p->name }}</td>
                            @foreach ($tage as $t)
                                @php
                                    $a = ($liste[$p->id] ?? collect())->first(fn ($a) => $a->umfasst($t));
                                    $frei = $t->dayOfWeekIso >= 6 || \Intranet\Modules\Zeiterfassung\Support\Feiertage::name($t, $land);
                                @endphp
                                <td class="px-0.5 py-1 text-center {{ $frei ? 'bg-gray-50' : '' }}">
                                    @if ($a)
                                        <span title="{{ $a->artText() }} ({{ $a->statusText() }})"
                                              class="block h-5 rounded {{ $a->status === 'genehmigt' ? 'bg-sky-400' : 'bg-amber-300' }} text-[10px] leading-5 text-white">
                                            {{ mb_substr($a->artText(), 0, 1) }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
