{{-- Sollzeit (Wochenstunden) + Kernarbeitszeit einer Person; erwartet $person, $aktuell (Zeitmodell|null), $laender, $standardLand. --}}
@php
    $kernStart = [];
    foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr) {
        $k = ($aktuell?->kernzeiten ?? [])[$nr] ?? [];
        $kernStart[$nr] = ['von' => $k['von'] ?? '', 'bis' => $k['bis'] ?? ''];
    }
@endphp
<form method="POST" action="{{ route('module.zeiterfassung.team.modell', $person) }}" class="space-y-3"
      x-data="kernRechner(@js($aktuell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($aktuell->wochenMinuten()) : '40:00'), @js($kernStart))">
    @csrf
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
        <label class="block text-sm">
            <span class="text-gray-600">Wochenstunden</span>
            <input type="text" name="wochenstunden" inputmode="decimal" required placeholder="z. B. 40 oder 38,5" x-model="wochen"
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
                        <th class="w-28 py-1 text-center font-normal">Summe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (['von', 'bis'] as $feld)
                        <tr>
                            <td class="pr-2 text-xs text-gray-600">{{ $feld }}</td>
                            @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                                <td class="p-0.5"><input type="time" step="900" name="kern[{{ $nr }}][{{ $feld }}]" x-model="kern[{{ $nr }}].{{ $feld }}"
                                                         aria-label="Kernzeit {{ $feld }} {{ \Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE[$nr] }}"
                                                         class="w-full min-w-20 rounded-lg border-gray-300 px-1 text-sm"></td>
                            @endforeach
                            <td></td>
                        </tr>
                    @endforeach
                    <tr class="text-xs">
                        <td class="pr-2 pt-1 text-gray-600">Std.</td>
                        @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                            <td class="pt-1 text-center tabular-nums text-gray-700" x-text="tagText({{ $nr }})"></td>
                        @endforeach
                        <td class="pt-1 text-center tabular-nums">
                            <span class="font-semibold text-gray-800" x-text="dauer(summe())"></span>
                            <span x-show="sollMinuten() > 0" :class="prozent() > 100 ? 'text-amber-700' : 'text-gray-500'"
                                  x-text="'= ' + prozent() + ' % von ' + dauer(sollMinuten())"></span>
                        </td>
                    </tr>
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

@once
    <script>
        document.addEventListener('alpine:init', () => {
            // Kernzeit je Tag in Stunden, Summe und Anteil an den Wochenstunden – rechnet beim Tippen mit.
            Alpine.data('kernRechner', (wochen, kern) => ({
                wochen, kern,
                minuten(t) { const m = /^(\d{1,2}):(\d{2})/.exec(t || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; },
                tag(nr) {
                    const von = this.minuten(this.kern[nr].von), bis = this.minuten(this.kern[nr].bis);
                    return von !== null && bis !== null && bis > von ? bis - von : 0;
                },
                tagText(nr) { const m = this.tag(nr); return m ? this.dauer(m) : '–'; },
                summe() { return [1, 2, 3, 4, 5, 6, 7].reduce((s, nr) => s + this.tag(nr), 0); },
                sollMinuten() {
                    const t = String(this.wochen || '').trim(), m = /^(\d{1,3}):([0-5]\d)$/.exec(t);
                    if (m) return (+m[1]) * 60 + (+m[2]);
                    return /^\d{1,3}([.,]\d{1,2})?$/.test(t) ? Math.round(parseFloat(t.replace(',', '.')) * 60) : 0;
                },
                prozent() { return this.sollMinuten() ? Math.round(this.summe() / this.sollMinuten() * 100) : 0; },
                dauer(m) { return Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0'); },
            }));
        });
    </script>
@endonce
