{{--
    Monatsübersicht einer Person mit Bearbeiten-Dialog.
    Erwartet: $person, $monat, $tage, $summe, $wochen, $antraege
              $route        Basis-Route der Seite (für das Blättern)
              $routeParams  Parameter der Seite (z. B. ['person' => $person])
              $bearbeiten   null | 'frei' | 'antrag'
              $routeBuchung / $routeLoeschen  Formularziele
--}}
@php
    $vor = $monat->copy()->subMonth()->format('Y-m');
    $nach = $monat->copy()->addMonth()->format('Y-m');
    $heute = today()->toDateString();
    $maxWoche = \Intranet\Modules\Zeiterfassung\Support\Einstellungen::int('max_woche');
@endphp

<div x-data="zeitDialog" class="rounded-xl border border-gray-200 bg-white">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
        <div class="flex items-center gap-2">
            <a href="{{ route($route, $routeParams + ['monat' => $vor]) }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">‹</a>
            <h2 class="min-w-36 text-center text-base font-semibold text-gray-800">{{ $monat->isoFormat('MMMM YYYY') }}</h2>
            <a href="{{ route($route, $routeParams + ['monat' => $nach]) }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">›</a>
            @if (! $monat->isSameMonth(now()))
                <a href="{{ route($route, $routeParams) }}" class="ml-1 text-sm text-indigo-600 hover:underline">aktueller Monat</a>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <span class="text-gray-500">Soll <strong class="text-gray-800">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['soll']) }}</strong></span>
            <span class="text-gray-500">Ist <strong class="text-gray-800">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['ist'] + $summe['gutschrift']) }}</strong></span>
            <span class="text-gray-500">Saldo Monat <strong class="{{ $summe['saldo'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($summe['saldo'], true) }}</strong></span>
            <a href="{{ route('module.zeiterfassung.auswertung.stundenzettel', ['person' => $person->id, 'monat' => $monat->format('Y-m')]) }}"
               target="_blank" class="text-indigo-600 hover:underline">Stundenzettel</a>
            @if ($bearbeiten)
                <button type="button" @click="neu('{{ $monat->isSameMonth(now()) ? $heute : $monat->toDateString() }}')"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                    + {{ $bearbeiten === 'antrag' ? 'Nachtrag beantragen' : 'Zeit eintragen' }}
                </button>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-semibold">Tag</th>
                    <th class="px-4 py-2 font-semibold">Zeiten</th>
                    <th class="px-4 py-2 text-right font-semibold">Pause</th>
                    <th class="px-4 py-2 text-right font-semibold">Ist</th>
                    <th class="px-4 py-2 text-right font-semibold">Soll</th>
                    <th class="px-4 py-2 text-right font-semibold">Saldo</th>
                    <th class="px-4 py-2 font-semibold">Hinweise</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($tage as $t)
                    @php
                        $d = $t->datum->toDateString();
                        $wochenende = $t->datum->dayOfWeekIso >= 6;
                        $kwEnde = $t->datum->dayOfWeekIso === 7 || $t->datum->isLastOfMonth();
                        $kw = $t->datum->isoFormat('GGGG-WW');
                        $tagAntraege = $antraege->filter(fn ($a) => $a->beginn->toDateString() === $d);
                    @endphp
                    <tr class="{{ $d === $heute ? 'bg-indigo-50/60' : ($wochenende || $t->feiertag ? 'bg-gray-50/70' : '') }} align-top">
                        <td class="whitespace-nowrap px-4 py-2">
                            <span class="font-medium {{ $wochenende || $t->feiertag ? 'text-gray-400' : 'text-gray-800' }}">{{ $t->datum->isoFormat('dd DD.MM.') }}</span>
                            @if ($t->feiertag)
                                <span class="block text-xs text-gray-500">{{ $t->feiertag }}</span>
                            @endif
                            @if ($t->abwesenheit)
                                <span class="mt-0.5 inline-block rounded bg-sky-100 px-1.5 py-0.5 text-xs font-medium text-sky-800">
                                    {{ $t->abwesenheit->artText() }}{{ $t->abwesenheit->halbtag ? ' (½)' : '' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($t->abschnitte as $b)
                                    @php
                                        $text = $b->beginn->format('H:i').'–'.($b->ende ? $b->ende->format('H:i').($b->ende->toDateString() !== $d ? ' (+1)' : '') : '…');
                                        $titel = 'Quelle: '.$b->quelleText().($b->terminal ? ' ('.$b->terminal->name.')' : '').($b->notiz ? ' – '.$b->notiz : '');
                                    @endphp
                                    @if ($bearbeiten)
                                        <button type="button" title="{{ $titel }}"
                                                @click="bearbeiten({{ $b->id }}, '{{ $d }}', '{{ $b->beginn->format('H:i') }}', '{{ $b->ende?->format('H:i') }}')"
                                                class="rounded-md px-2 py-0.5 font-mono text-xs {{ $b->istOffen() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }} hover:ring-2 hover:ring-indigo-300">
                                            {{ $text }}
                                        </button>
                                    @else
                                        <span title="{{ $titel }}" class="rounded-md px-2 py-0.5 font-mono text-xs {{ $b->istOffen() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $text }}</span>
                                    @endif
                                @endforeach
                                @foreach ($tagAntraege as $a)
                                    <span title="{{ $a->notiz }}" class="rounded-md border border-dashed border-amber-400 bg-amber-50 px-2 py-0.5 font-mono text-xs text-amber-800">
                                        {{ $a->antragText() }}: {{ $a->beginn->format('H:i') }}–{{ $a->ende?->format('H:i') ?? '…' }} (offen)
                                    </span>
                                @endforeach
                                @if ($bearbeiten && $t->datum->lte(today()))
                                    <button type="button" @click="neu('{{ $d }}')" class="rounded-md px-1.5 py-0.5 text-xs text-gray-400 hover:bg-gray-100 hover:text-indigo-600" title="Zeit für diesen Tag eintragen">+</button>
                                @endif
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right text-gray-600">
                            @if ($t->abschnitte->isNotEmpty())
                                {{ $t->pause }}′
                                @if ($t->abzug)
                                    <span class="block text-xs text-amber-700" title="Gesetzliche Pause fehlte und wurde abgezogen">+{{ $t->abzug }}′ abgezogen</span>
                                @endif
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right font-medium text-gray-800">
                            {{ $t->abschnitte->isNotEmpty() ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->ist()) : '' }}
                            @if ($t->gutschrift)
                                <span class="block text-xs font-normal text-sky-700">+{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->gutschrift) }} {{ $t->abwesenheit?->artText() }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right text-gray-500">{{ $t->soll ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->soll) : '' }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-right {{ $t->saldo() < 0 ? 'text-red-600' : 'text-green-700' }}">
                            {{ ($t->mitModell && ($t->soll || $t->abschnitte->isNotEmpty()) && $d <= $heute) ? \Intranet\Modules\Zeiterfassung\Support\Format::dauer($t->saldo(), true) : '' }}
                        </td>
                        <td class="px-4 py-2">
                            @foreach ($t->warnungen as $regel => $w)
                                <span class="mb-0.5 block text-xs {{ $regel === 'sonntag' ? 'text-gray-500' : 'text-red-700' }}">⚠ {{ $w }}</span>
                            @endforeach
                        </td>
                    </tr>
                    @if ($kwEnde && ($wochen[$kw] ?? 0) > $maxWoche)
                        <tr class="bg-red-50">
                            <td colspan="7" class="px-4 py-1.5 text-xs text-red-700">
                                ⚠ KW {{ $t->datum->isoWeek }}: {{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($wochen[$kw]) }} Std. gearbeitet – mehr als {{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($maxWoche) }} Std. (§ 3 ArbZG)
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($bearbeiten)
        {{-- Dialog: Zeit eintragen / ändern / löschen --}}
        <div x-show="offen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="offen = false">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl" @click.outside="offen = false">
                <h3 class="text-base font-semibold text-gray-800" x-text="id ? 'Zeit ändern' : 'Zeit eintragen'"></h3>
                @if ($bearbeiten === 'antrag')
                    <p class="mt-1 text-xs text-amber-700">Die Änderung gilt erst, wenn die Leitung sie freigibt.</p>
                @endif
                <form method="POST" action="{{ $routeBuchung }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="buchung_id" :value="id">
                    <label class="block text-sm">
                        <span class="text-gray-600">Datum</span>
                        <input type="date" name="datum" x-model="datum" max="{{ $heute }}" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm">
                            <span class="text-gray-600">Von</span>
                            <input type="time" name="von" x-model="von" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        </label>
                        <label class="block text-sm">
                            <span class="text-gray-600">Bis</span>
                            <input type="time" name="bis" x-model="bis" {{ $bearbeiten === 'antrag' || ! empty($nurMitEnde) ? 'required' : '' }} class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <span class="mt-0.5 block text-xs text-gray-400">Früher als „Von" = am Folgetag</span>
                        </label>
                    </div>
                    <label class="block text-sm">
                        <span class="text-gray-600">Begründung {{ $bearbeiten === 'antrag' ? '(Pflicht)' : '(optional)' }}</span>
                        <input type="text" name="notiz" x-model="notiz" maxlength="500" {{ $bearbeiten === 'antrag' ? 'required' : '' }}
                               placeholder="z. B. Stempeln vergessen" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    </label>
                    <div class="flex items-center justify-between gap-2 pt-2">
                        <div>
                            <button type="button" x-show="id" @click="loeschen($el.form, '{{ $routeLoeschen }}', {{ $bearbeiten === 'antrag' ? 'true' : 'false' }})"
                                    class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50">Löschen</button>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="offen = false" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                {{ $bearbeiten === 'antrag' ? 'Beantragen' : 'Speichern' }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('zeitDialog', () => ({
                offen: false, id: '', datum: '', von: '', bis: '', notiz: '',
                neu(datum) { Object.assign(this, { offen: true, id: '', datum, von: '', bis: '', notiz: '' }); },
                bearbeiten(id, datum, von, bis) { Object.assign(this, { offen: true, id, datum, von, bis, notiz: '' }); },
                async loeschen(form, ziel, begruendungPflicht) {
                    if (begruendungPflicht && ! this.notiz.trim()) {
                        await window.hinweis('Bitte eine Begründung angeben.');
                        return;
                    }
                    const frage = begruendungPflicht ? 'Löschung dieser Zeit beantragen?' : 'Diese Zeit wirklich löschen?';
                    if (window.bestaetige && ! await window.bestaetige(frage, { knopf: 'Löschen' })) return;
                    form.action = ziel;
                    form.submit();
                },
            }));
        });
    </script>
@endonce
