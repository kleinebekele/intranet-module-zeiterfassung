{{-- Sollzeit (Wochenstunden) + Kernarbeitszeit einer Person; erwartet $person, $aktuell (Zeitmodell|null), $laender, $standardLand. --}}
<form method="POST" action="{{ route('module.zeiterfassung.team.modell', $person) }}" class="space-y-3">
    @csrf
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
        <label class="block text-sm">
            <span class="text-gray-600">Wochenstunden</span>
            <input type="text" name="wochenstunden" inputmode="decimal" required placeholder="z. B. 40 oder 38,5"
                   value="{{ $aktuell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($aktuell->wochenMinuten()) : '40:00' }}"
                   class="mt-1 w-full rounded-lg border-gray-300 text-sm">
        </label>
        <label class="block text-sm">
            <span class="text-gray-600">Gültig ab</span>
            <input type="date" name="gueltig_ab" required value="{{ $aktuell ? today()->startOfMonth()->toDateString() : today()->startOfYear()->toDateString() }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
        </label>
        <label class="block text-sm">
            <span class="text-gray-600">Urlaubstage / Jahr</span>
            <input type="number" name="urlaubstage" step="0.5" min="0" max="99" required value="{{ $aktuell?->urlaubstage ?? 30 }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
        </label>
        <label class="block text-sm">
            <span class="text-gray-600">Feiertage nach</span>
            <select name="bundesland" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                <option value="">Standard ({{ $laender[$standardLand] ?? $standardLand }})</option>
                @foreach ($laender as $code => $name)
                    <option value="{{ $code }}" @selected($aktuell?->bundesland === $code)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div>
        <div class="text-sm text-gray-600">Kernarbeitszeit (Orientierung)</div>
        <div class="mt-1 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500">
                        <th class="w-12 py-1 text-left font-normal"></th>
                        @foreach (\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE as $kurz)
                            <th class="py-1 text-center font-normal">{{ $kurz }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach (['von', 'bis'] as $feld)
                        <tr>
                            <td class="pr-2 text-xs text-gray-600">{{ $feld }}</td>
                            @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                                <td class="p-0.5"><input type="time" step="900" name="kern[{{ $nr }}][{{ $feld }}]" value="{{ (($aktuell?->kernzeiten ?? [])[$nr] ?? [])[$feld] ?? '' }}"
                                                         aria-label="Kernzeit {{ $feld }} {{ \Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE[$nr] }}"
                                                         class="w-full min-w-20 rounded-lg border-gray-300 px-1 text-sm"></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-1 text-xs text-gray-400">
            Die Tage mit Kernarbeitszeit sind die Arbeitstage – auf sie werden die Wochenstunden verteilt (ohne Kernzeit: Mo–Fr).
            Für den Dienstplan ist die Kernzeit eine Orientierung, keine Pflicht: Der Vorschlag plant bevorzugt an diesen Tagen und zu diesen Zeiten.
        </p>
    </div>

    <div class="flex justify-end">
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Sollzeit speichern</button>
    </div>
</form>
