<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Meine Zeiten</h1>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    @if (! $teilnehmer && $tage && collect($tage)->every(fn ($t) => $t->abschnitte->isEmpty()))
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Du bist keiner Zeiterfassungs-Gruppe zugeordnet. Wenn deine Zeiten erfasst werden sollen, melde dich bei der Verwaltung.
        </div>
    @else
        <div class="space-y-4">
            @include('zeiterfassung::partials.stempeluhr', [
                'status' => $status,
                'route' => route('module.zeiterfassung.meine.stempeln'),
                'darfStempeln' => $webStempeln,
            ])

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Stundenkonto (bis gestern)</div>
                    <div class="mt-1 text-2xl font-bold {{ $kontostand < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $hatModell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($kontostand, true) : '–' }}</div>
                    @unless ($hatModell)
                        <div class="text-xs text-gray-400">Noch keine Sollzeit hinterlegt.</div>
                    @endunless
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Resturlaub {{ $monat->year }}</div>
                    <div class="mt-1 text-2xl font-bold text-gray-800">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['rest']) }} Tage</div>
                    <div class="text-xs text-gray-400">
                        {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['genommen']) }} genommen
                        @if ($urlaub['geplant'] > 0) · {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['geplant']) }} beantragt @endif
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Offene Anträge</div>
                    <div class="mt-1 text-2xl font-bold text-gray-800">{{ $antraege->count() }}</div>
                    <div class="text-xs text-gray-400">in diesem Monat</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-400">Terminal</div>
                    <div class="mt-1 text-sm text-gray-700">{{ $hatCode ? 'Persönlicher Code ist gesetzt.' : 'Noch kein Code.' }}</div>
                    <form method="POST" action="{{ route('module.zeiterfassung.meine.code') }}" class="mt-2"
                          data-bestaetigen="{{ $hatCode ? 'Neuen Code erzeugen? Der alte gilt dann nicht mehr.' : 'Code erzeugen?' }}">
                        @csrf
                        <button type="submit" class="text-sm text-indigo-600 hover:underline">{{ $hatCode ? 'Neuen Code erzeugen' : 'Code erzeugen' }}</button>
                    </form>
                </div>
            </div>

            @if ($verstoesse->isNotEmpty())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    <div class="font-semibold">Hinweise zum Arbeitszeitgesetz (letzte 30 Tage)</div>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($verstoesse as $v)
                            <li>{{ $v->datum->format('d.m.Y') }}: {{ $v->text }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @include('zeiterfassung::partials.monat', [
                'route' => 'module.zeiterfassung.meine.index',
                'routeParams' => [],
                'bearbeiten' => $nachtrag,
                'routeBuchung' => route('module.zeiterfassung.meine.buchung'),
                'routeLoeschen' => route('module.zeiterfassung.meine.loeschen'),
                'nurMitEnde' => true,
            ])

            @unless ($nachtrag)
                <p class="text-xs text-gray-400">Stimmt eine Zeit nicht? Bitte bei der Leitung melden – sie kann sie korrigieren.</p>
            @endunless
        </div>
    @endif
</x-app-layout>
