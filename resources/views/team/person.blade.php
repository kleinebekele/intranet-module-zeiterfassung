<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">{{ $person->name }}</h1>
            <a href="{{ route('module.zeiterfassung.team.index') }}" class="text-sm text-indigo-600 hover:underline">zurück zum Team</a>
        </div>
    </x-slot>

    @php
        $aktuell = $modelle->first(fn ($m) => $m->gueltig_ab->lte(today())) ?? $modelle->last();
    @endphp

    @include('zeiterfassung::partials.meldung')

    <div class="space-y-4">
        @include('zeiterfassung::partials.stempeluhr', [
            'status' => $status,
            'route' => route('module.zeiterfassung.team.stempeln', $person),
            'darfStempeln' => true,
        ])

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Stundenkonto (bis gestern)</div>
                <div class="mt-1 text-2xl font-bold {{ $kontostand < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $hatModell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($kontostand, true) : '–' }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Resturlaub {{ $monat->year }}</div>
                <div class="mt-1 text-2xl font-bold text-gray-800">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['rest']) }} Tage</div>
                <div class="text-xs text-gray-400">Anspruch {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['anspruch']) }} + Übertrag {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['uebertrag']) }} − genommen {{ \Intranet\Modules\Zeiterfassung\Support\Format::tage($urlaub['genommen']) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-gray-400">Sollzeit</div>
                <div class="mt-1 text-2xl font-bold text-gray-800">{{ $aktuell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($aktuell->wochenMinuten()).' Std./Woche' : '–' }}</div>
                <div class="text-xs text-gray-400">{{ $aktuell ? $aktuell->arbeitstageJeWoche().' Arbeitstage, '.\Intranet\Modules\Zeiterfassung\Support\Format::tage((float) $aktuell->urlaubstage).' Urlaubstage' : 'Bitte unten hinterlegen.' }}</div>
            </div>
        </div>

        @if ($verstoesse->isNotEmpty())
            <div id="verstoesse" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-semibold">Verstöße gegen das Arbeitszeitgesetz</div>
                    <form method="POST" action="{{ route('module.zeiterfassung.team.verstoesse', $person) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-red-300 bg-white px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-100">Alle als gesehen markieren</button>
                    </form>
                </div>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($verstoesse as $v)
                        <li>{{ $v->datum->isoFormat('dd DD.MM.YYYY') }}: {{ $v->text }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('zeiterfassung::partials.monat', [
            'route' => 'module.zeiterfassung.team.person',
            'routeParams' => ['person' => $person->id],
            'bearbeiten' => 'frei',
            'routeBuchung' => route('module.zeiterfassung.team.buchung', $person),
            'routeLoeschen' => route('module.zeiterfassung.team.loeschen', $person),
        ])

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {{-- Sollzeit --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-base font-semibold text-gray-800">Sollarbeitszeit</h2>
                <p class="mt-1 text-xs text-gray-500">Gilt ab dem Stichtag; vorherige Zeiträume rechnen mit dem alten Modell weiter.</p>
                <form method="POST" action="{{ route('module.zeiterfassung.team.modell', $person) }}" class="mt-3 space-y-3">
                    @csrf
                    <div class="grid grid-cols-7 gap-2">
                        @foreach (\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE as $nr => $kurz)
                            <label class="block text-center text-xs">
                                <span class="text-gray-500">{{ $kurz }}</span>
                                <input type="text" name="soll[{{ $nr }}]" inputmode="decimal" placeholder="0:00"
                                       value="{{ old("soll.$nr", $aktuell ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($aktuell->sollFuer($nr)) : ($nr <= 5 ? '8:00' : '0:00')) }}"
                                       class="mt-1 w-full rounded-lg border-gray-300 px-1 text-center text-sm">
                            </label>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="block text-sm">
                            <span class="text-gray-600">Gültig ab</span>
                            <input type="date" name="gueltig_ab" required value="{{ old('gueltig_ab', $aktuell ? today()->startOfMonth()->toDateString() : today()->startOfYear()->toDateString()) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <label class="block text-sm">
                            <span class="text-gray-600">Urlaubstage / Jahr</span>
                            <input type="number" name="urlaubstage" step="0.5" min="0" max="99" required value="{{ old('urlaubstage', $aktuell?->urlaubstage ?? 30) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <label class="block text-sm">
                            <span class="text-gray-600">Feiertage nach</span>
                            <select name="bundesland" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option value="">Standard ({{ $laender[$standardLand] ?? $standardLand }})</option>
                                @foreach ($laender as $code => $name)
                                    <option value="{{ $code }}" @selected(old('bundesland', $aktuell?->bundesland) === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Sollzeit speichern</button>
                    </div>
                </form>

                @if ($modelle->isNotEmpty())
                    <table class="mt-4 w-full text-xs">
                        <thead class="text-left text-gray-400">
                            <tr><th class="py-1">ab</th>@foreach (\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE as $kurz)<th class="py-1 text-center">{{ $kurz }}</th>@endforeach<th class="py-1 text-right">Woche</th><th class="py-1 text-right">Urlaub</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($modelle as $m)
                                <tr>
                                    <td class="py-1">{{ $m->gueltig_ab->format('d.m.Y') }}</td>
                                    @foreach (array_keys(\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE) as $nr)
                                        <td class="py-1 text-center text-gray-600">{{ $m->sollFuer($nr) ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($m->sollFuer($nr)) : '–' }}</td>
                                    @endforeach
                                    <td class="py-1 text-right">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($m->wochenMinuten()) }}</td>
                                    <td class="py-1 text-right">{{ \Intranet\Modules\Zeiterfassung\Support\Format::tage((float) $m->urlaubstage) }}</td>
                                    <td class="py-1 text-right">
                                        <form method="POST" action="{{ route('module.zeiterfassung.team.modell.entfernen', [$person, $m]) }}" data-bestaetigen="Sollzeit ab {{ $m->gueltig_ab->format('d.m.Y') }} entfernen?">
                                            @csrf
                                            <button type="submit" class="text-red-500 hover:underline">entfernen</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="space-y-4">
                {{-- Abwesenheit eintragen --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-gray-800">Abwesenheit eintragen</h2>
                    <form method="POST" action="{{ route('module.zeiterfassung.team.abwesenheit', $person) }}" class="mt-3 space-y-3"
                          x-data="{ von: '', bis: '', art: 'urlaub' }">
                        @csrf
                        @include('zeiterfassung::partials.abwesenheit-felder')
                        <div class="flex justify-end">
                            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Eintragen (gilt sofort)</button>
                        </div>
                    </form>
                    @if ($abwesenheiten->isNotEmpty())
                        <ul class="mt-4 divide-y divide-gray-100 text-sm">
                            @foreach ($abwesenheiten as $a)
                                <li class="flex items-center justify-between gap-2 py-1.5">
                                    <span>
                                        <strong>{{ $a->artText() }}</strong>
                                        {{ $a->von->format('d.m.') }}@if (! $a->von->eq($a->bis))–{{ $a->bis->format('d.m.Y') }}@else{{ $a->von->format('Y') }}@endif
                                        @if ($a->halbtag) (½) @endif
                                        @include('zeiterfassung::partials.abwesenheit-status', ['a' => $a])
                                    </span>
                                    @if (in_array($a->status, ['genehmigt', 'beantragt']))
                                        <form method="POST" action="{{ route('module.zeiterfassung.team.abwesenheit.stornieren', [$person, $a]) }}" data-bestaetigen="{{ $a->artText() }} stornieren?">
                                            @csrf
                                            <button type="submit" class="text-xs text-red-500 hover:underline">stornieren</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Chip & Code --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5" x-data="chipLesen">
                    <h2 class="text-base font-semibold text-gray-800">Chip & Terminal-Code</h2>
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @forelse ($ausweise as $aw)
                            <li class="flex items-center justify-between py-1.5">
                                <span>
                                    @if ($aw->art === 'code')
                                        Terminal-Code <span class="text-gray-400">(gesetzt {{ $aw->updated_at->format('d.m.Y') }})</span>
                                    @else
                                        Chip <span class="font-mono text-gray-600">{{ $aw->wert }}</span> <span class="text-gray-400">{{ $aw->bezeichnung }}</span>
                                    @endif
                                </span>
                                <form method="POST" action="{{ route('module.zeiterfassung.team.ausweis.entfernen', [$person, $aw]) }}" data-bestaetigen="Wirklich entfernen?">
                                    @csrf
                                    <button type="submit" class="text-xs text-red-500 hover:underline">entfernen</button>
                                </form>
                            </li>
                        @empty
                            <li class="py-1.5 text-gray-400">Noch kein Chip und kein Code.</li>
                        @endforelse
                    </ul>
                    <p class="mt-1 text-xs text-gray-400">Chips aus der Kantine gelten am Terminal automatisch mit.</p>

                    <form method="POST" action="{{ route('module.zeiterfassung.team.chip', $person) }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="kennung" x-ref="kennung" required placeholder="Chip-Kennung (Leser hier hinein)"
                               class="min-w-48 flex-1 rounded-lg border-gray-300 font-mono text-sm">
                        <input type="text" name="bezeichnung" placeholder="Bezeichnung (optional)" class="min-w-40 flex-1 rounded-lg border-gray-300 text-sm">
                        <button type="button" x-show="nfc" @click="lesen" class="rounded-lg border border-indigo-200 px-3 py-2 text-sm text-indigo-700 hover:bg-indigo-50" x-text="liest ? 'Chip auflegen …' : 'per NFC lesen'"></button>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Chip zuordnen</button>
                    </form>

                    <form method="POST" action="{{ route('module.zeiterfassung.team.code', $person) }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="code" inputmode="numeric" pattern="\d{4,10}" placeholder="Wunsch-Code (leer = zufällig)"
                               class="min-w-48 flex-1 rounded-lg border-gray-300 font-mono text-sm">
                        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Code neu setzen</button>
                    </form>
                </div>

                {{-- Übertrag --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-gray-800">Übertrag ins Jahr {{ $monat->year }}</h2>
                    <form method="POST" action="{{ route('module.zeiterfassung.team.konto', $person) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @csrf
                        <input type="hidden" name="jahr" value="{{ $monat->year }}">
                        <label class="block text-sm">
                            <span class="text-gray-600">Resturlaub (Tage)</span>
                            <input type="number" step="0.5" name="urlaub_uebertrag" value="{{ $konto->urlaub_uebertrag ?? 0 }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <label class="block text-sm">
                            <span class="text-gray-600">Stunden (z. B. 12:30, -4:00)</span>
                            <input type="text" name="saldo_uebertrag" value="{{ $konto->saldo_uebertrag ? str_replace('−', '-', \Intranet\Modules\Zeiterfassung\Support\Format::dauer($konto->saldo_uebertrag)) : '0:00' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <div class="flex items-end">
                            <button type="submit" class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Speichern</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('chipLesen', () => ({
                nfc: 'NDEFReader' in window,
                liest: false,
                async lesen() {
                    try {
                        const reader = new NDEFReader();
                        const ctrl = new AbortController();
                        await reader.scan({ signal: ctrl.signal });
                        this.liest = true;
                        reader.onreading = (e) => { this.$refs.kennung.value = e.serialNumber || ''; this.liest = false; ctrl.abort(); };
                    } catch (err) {
                        this.liest = false;
                        window.hinweis('NFC nicht möglich: ' + (err && err.message ? err.message : err));
                    }
                },
            }));
        });
    </script>
</x-app-layout>
