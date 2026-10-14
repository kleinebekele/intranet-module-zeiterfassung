{{-- Sollarbeitszeit + Kernarbeitszeit einer Person; erwartet $person, $aktuell (Zeitmodell|null), $laender, $standardLand. --}}
<form method="POST" action="{{ route('module.zeiterfassung.team.modell', $person) }}" class="space-y-3">
    @csrf
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500">
                    <th class="w-28 py-1 text-left font-normal"></th>
                    @foreach (\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE as $kurz)
                        <th class="py-1 text-center font-normal">{{ $kurz }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="pr-2 text-xs text-gray-600">Sollzeit</td>
                    @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                        <td class="p-0.5">
                            <input type="text" name="soll[{{ $nr }}]" inputmode="decimal" placeholder="0:00" aria-label="Sollzeit {{ \Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE[$nr] }}"
                                   value="{{ $aktuell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($aktuell->sollFuer($nr)) : ($nr <= 5 ? '8:00' : '0:00') }}"
                                   class="w-full min-w-14 rounded-lg border-gray-300 px-1 text-center text-sm">
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td class="pr-2 text-xs text-gray-600">Kernzeit von</td>
                    @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                        <td class="p-0.5"><input type="time" step="900" name="kern[{{ $nr }}][von]" value="{{ (($aktuell?->kernzeiten ?? [])[$nr] ?? [])['von'] ?? '' }}" aria-label="Kernzeit von {{ \Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE[$nr] }}" class="w-full min-w-20 rounded-lg border-gray-300 px-1 text-sm"></td>
                    @endforeach
                </tr>
                <tr>
                    <td class="pr-2 text-xs text-gray-600">Kernzeit bis</td>
                    @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                        <td class="p-0.5"><input type="time" step="900" name="kern[{{ $nr }}][bis]" value="{{ (($aktuell?->kernzeiten ?? [])[$nr] ?? [])['bis'] ?? '' }}" aria-label="Kernzeit bis {{ \Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE[$nr] }}" class="w-full min-w-20 rounded-lg border-gray-300 px-1 text-sm"></td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-400">Kernzeit leer = keine Vorgabe. Der Dienstplan legt automatisch geplante Schichten so, dass sie die Kernzeit abdecken.</p>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
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
    <div class="flex justify-end">
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Sollzeit speichern</button>
    </div>
</form>
