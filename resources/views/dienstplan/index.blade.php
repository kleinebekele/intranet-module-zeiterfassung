<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Dienstplan</h1>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Abteilungen</h2>
            <div class="mt-3 divide-y divide-gray-100">
                @forelse ($abteilungen as $a)
                    <div class="flex flex-wrap items-center gap-3 py-3">
                        <div class="min-w-48 flex-1">
                            <a href="{{ route('module.zeiterfassung.dienstplan.zeigen', [$a, 'monat' => $monat->format('Y-m')]) }}" class="font-medium text-indigo-700 hover:underline">{{ $a->name }}</a>
                            <div class="text-xs text-gray-400">{{ $a->personen_count }} Personen</div>
                        </div>
                        @foreach ([0, 1, 2] as $plus)
                            @php $m = now()->startOfMonth()->addMonths($plus); @endphp
                            <a href="{{ route('module.zeiterfassung.dienstplan.zeigen', [$a, 'monat' => $m->format('Y-m')]) }}"
                               class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">{{ $m->isoFormat('MMMM') }}</a>
                        @endforeach
                        @if ($planer)
                            <a href="{{ route('module.zeiterfassung.dienstplan.abteilung', $a) }}" class="rounded-lg px-3 py-1.5 text-sm text-gray-500 hover:bg-gray-100">einrichten</a>
                        @endif
                    </div>
                @empty
                    <p class="py-3 text-sm text-gray-400">Noch keine Abteilung.</p>
                @endforelse
            </div>
            @if ($planer)
                <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.abteilung.anlegen') }}" class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                    @csrf
                    <input type="text" name="name" required maxlength="150" placeholder="Neue Abteilung, z. B. Filiale Bielefeld oder Lager" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm">
                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Anlegen</button>
                </form>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Meine nächsten Schichten</h2>
            <div class="mt-3 divide-y divide-gray-100 text-sm">
                @forelse ($meine as $s)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="{{ $s->datum->isToday() ? 'font-semibold text-indigo-700' : 'text-gray-800' }}">{{ $s->datum->isoFormat('dd DD.MM.') }}</span>
                        <span class="text-gray-800">{{ $s->von }}–{{ $s->bis }}</span>
                        <span class="truncate text-xs text-gray-400">{{ $s->dienstplan->abteilung->name }}</span>
                    </div>
                @empty
                    <p class="py-2 text-gray-400">Keine veröffentlichten Schichten.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
