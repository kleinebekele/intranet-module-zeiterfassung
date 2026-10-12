<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Dienstplan {{ $abteilung->name }}</h1>
            <div class="flex flex-wrap gap-3 text-sm">
                <a href="{{ route('module.zeiterfassung.dienstplan.index') }}" class="text-indigo-600 hover:underline">alle Abteilungen</a>
                @if ($planer)
                    <a href="{{ route('module.zeiterfassung.dienstplan.abteilung', $abteilung) }}" class="text-indigo-600 hover:underline">Abteilung einrichten</a>
                @endif
            </div>
        </div>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    @php
        $mq = ['monat' => $monat->format('Y-m')];
        $ich = auth()->id();
    @endphp

    <div x-data="schichtDialog" class="rounded-xl border border-gray-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-3">
            <a href="?monat={{ $monat->copy()->subMonth()->format('Y-m') }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">‹</a>
            <h2 class="min-w-36 text-center text-base font-semibold text-gray-800">{{ $monat->isoFormat('MMMM YYYY') }}</h2>
            <a href="?monat={{ $monat->copy()->addMonth()->format('Y-m') }}" class="rounded-lg border border-gray-200 px-2.5 py-1 text-sm text-gray-600 hover:bg-gray-50">›</a>

            @if ($plan)
                <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium {{ $plan->istVeroeffentlicht() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                    {{ $plan->istVeroeffentlicht() ? 'veröffentlicht '.$plan->veroeffentlicht_am?->format('d.m.Y') : 'Entwurf' }}
                </span>
            @endif

            <div class="ml-auto flex flex-wrap gap-2">
                @if ($sichtbar)
                    <a href="{{ route('module.zeiterfassung.dienstplan.druck', [$abteilung] + $mq) }}" target="_blank"
                       class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Drucken</a>
                @endif
                @if ($planer)
                    <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.vorschlagen', [$abteilung] + $mq) }}"
                          @if ($plan && $schichten->isNotEmpty())
                              data-bestaetigen="Alle Schichten dieses Monats werden durch einen neuen Vorschlag ersetzt – auch von Hand geänderte."
                              data-titel="Neu vorschlagen?" data-knopf="Ersetzen"
                          @endif>
                        @csrf
                        <button type="submit" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-sm text-indigo-700 hover:bg-indigo-50">
                            {{ $plan && $schichten->isNotEmpty() ? 'Neu vorschlagen' : 'Vorschlag erstellen' }}
                        </button>
                    </form>
                    @if ($plan)
                        <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.status', [$abteilung] + $mq) }}">
                            @csrf
                            @if ($plan->istVeroeffentlicht())
                                <input type="hidden" name="status" value="entwurf">
                                <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Zurück auf Entwurf</button>
                            @else
                                <input type="hidden" name="status" value="veroeffentlicht">
                                <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Veröffentlichen</button>
                            @endif
                        </form>
                    @endif
                @endif
            </div>
        </div>

        @if ($planer && $ohneBesetzung)
            <p class="px-4 py-6 text-sm text-gray-500">
                Für diese Abteilung ist noch keine Besetzung eingetragen.
                <a href="{{ route('module.zeiterfassung.dienstplan.abteilung', $abteilung) }}" class="text-indigo-600 hover:underline">Jetzt einrichten</a>
            </p>
        @elseif (! $sichtbar)
            <p class="px-4 py-6 text-sm text-gray-500">
                {{ $planer ? 'Für diesen Monat gibt es noch keinen Plan – „Vorschlag erstellen" legt einen an.' : 'Der Dienstplan für diesen Monat ist noch nicht veröffentlicht.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-gray-500">
                            <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left font-semibold">Person</th>
                            @foreach ($tage as $d => $t)
                                <th class="min-w-12 px-0.5 py-2 text-center font-normal {{ $t['offen'] ? '' : 'text-gray-300' }} {{ $t['datum']->isToday() ? 'font-bold text-indigo-600' : '' }}"
                                    title="{{ $t['hinweis'] }}">
                                    {{ $t['datum']->isoFormat('dd') }}<br>{{ $t['datum']->day }}
                                    @if ($t['hinweis'])<div class="truncate text-[9px] {{ $t['offen'] ? 'text-indigo-500' : 'text-red-400' }}">{{ mb_substr($t['hinweis'], 0, 6) }}</div>@endif
                                </th>
                            @endforeach
                            <th class="px-2 py-2 text-right font-semibold">Geplant</th>
                            <th class="px-2 py-2 text-right font-semibold">Soll</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($personen as $uid => $p)
                            @php $pr = $pruefung['personen'][$uid]; @endphp
                            <tr class="{{ $uid === $ich ? 'bg-indigo-50/40' : '' }}">
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-1.5 text-sm text-gray-800">
                                    {{ $p['user']->name }}
                                    @if ($p['aushilfe'])<span class="ml-1 rounded bg-gray-100 px-1 text-[10px] text-gray-500">Aushilfe</span>@endif
                                </td>
                                @foreach ($tage as $d => $t)
                                    @php
                                        $s = $schichten[$uid][$d] ?? null;
                                        $warn = $pruefung['zellen'][$uid][$d] ?? [];
                                        $weg = $p['abwesend'][$d] ?? null;
                                    @endphp
                                    <td class="px-0.5 py-1 text-center align-top {{ $t['offen'] ? '' : 'bg-gray-50' }}">
                                        @if ($planer)
                                            <button type="button"
                                                    @click="oeffne({{ $uid }}, @js($p['user']->name), '{{ $d }}', @js($t['datum']->isoFormat('dd DD.MM.')), '{{ $s?->von }}', '{{ $s?->bis }}', '{{ $s ? $s->pause : '' }}', @js($s?->notiz ?? ''))"
                                                    title="{{ implode(' · ', array_filter([$weg, ...$warn, $s?->notiz])) }}"
                                                    class="block w-full rounded px-0.5 py-0.5 leading-tight {{ $s ? ($warn ? 'bg-red-100 text-red-800' : 'bg-indigo-100 text-indigo-800') : ($weg ? 'bg-sky-50 text-sky-700' : 'text-gray-300 hover:bg-gray-100') }}">
                                                @if ($s){{ $s->von }}<br>{{ $s->bis }}@elseif ($weg){{ mb_substr($weg, 0, 1) }}@else&nbsp;<br>&nbsp;@endif
                                            </button>
                                        @else
                                            <div title="{{ implode(' · ', array_filter([$weg, $s?->notiz])) }}"
                                                 class="rounded px-0.5 py-0.5 leading-tight {{ $s ? 'bg-indigo-100 text-indigo-800' : ($weg ? 'bg-sky-50 text-sky-700' : '') }}">
                                                @if ($s){{ $s->von }}<br>{{ $s->bis }}@elseif ($weg){{ mb_substr($weg, 0, 1) }}@endif
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="whitespace-nowrap px-2 py-1.5 text-right text-sm text-gray-800">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($pr['geplant']) }}</td>
                                <td class="whitespace-nowrap px-2 py-1.5 text-right text-sm text-gray-500">
                                    @if ($p['aushilfe'])
                                        {{ $pr['budget'] !== null ? 'max. '.\Intranet\Modules\Zeiterfassung\Support\Format::dauer($pr['budget']) : '–' }}
                                    @else
                                        {{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($pr['ziel']) }}
                                        @if (abs($pr['geplant'] - $pr['ziel']) > 60)
                                            <div class="text-xs {{ $pr['geplant'] > $pr['ziel'] ? 'text-amber-700' : 'text-red-600' }}">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($pr['geplant'] - $pr['ziel'], true) }}</div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-200 bg-gray-50">
                            <td class="sticky left-0 z-10 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-700">Besetzung</td>
                            @foreach ($tage as $d => $t)
                                @php $pt = $pruefung['tage'][$d]; @endphp
                                <td class="px-0.5 py-1 text-center" title="{{ implode(' · ', array_merge(array_map(fn ($x) => 'Mindestens: '.$x, $pt['fehlt']), array_map(fn ($x) => 'Optional: '.$x, $pt['optional']))) }}">
                                    @if ($t['offen'])
                                        @if ($pt['fehlt'])
                                            <span class="inline-block rounded bg-red-500 px-1 text-[10px] font-bold text-white">!</span>
                                        @elseif ($pt['optional'])
                                            <span class="inline-block rounded bg-amber-300 px-1 text-[10px] text-white">~</span>
                                        @else
                                            <span class="text-green-600">✓</span>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap gap-4 border-t border-gray-200 px-4 py-2 text-xs text-gray-500">
                <span><span class="text-green-600">✓</span> besetzt</span>
                <span><span class="inline-block rounded bg-amber-300 px-1 text-white">~</span> optionale Besetzung fehlt</span>
                <span><span class="inline-block rounded bg-red-500 px-1 font-bold text-white">!</span> unter Mindestbesetzung</span>
                <span><span class="inline-block rounded bg-sky-50 px-1 text-sky-700">U</span> abwesend</span>
                @if ($planer)<span>Zelle anklicken zum Ändern</span>@endif
            </div>
        @endif

        @if ($planer && $sichtbar)
            {{-- Dialog: Schicht eintragen / ändern / entfernen --}}
            <div x-show="offen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="offen = false">
                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl" @click.outside="offen = false">
                    <h3 class="text-base font-semibold text-gray-800"><span x-text="name"></span> · <span x-text="tag"></span></h3>
                    <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.schicht', $abteilung) }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="user_id" :value="user">
                        <input type="hidden" name="datum" :value="datum">
                        <div class="grid grid-cols-3 gap-3">
                            <label class="block text-sm"><span class="text-gray-600">Von</span>
                                <input type="time" name="von" x-model="von" step="900" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                            <label class="block text-sm"><span class="text-gray-600">Bis</span>
                                <input type="time" name="bis" x-model="bis" step="900" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                            <label class="block text-sm"><span class="text-gray-600">Pause (Min.)</span>
                                <input type="number" name="pause" x-model="pause" min="0" max="240" placeholder="auto" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                        </div>
                        <label class="block text-sm"><span class="text-gray-600">Notiz (optional)</span>
                            <input type="text" name="notiz" x-model="notiz" maxlength="200" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                        <p class="text-xs text-gray-400">Pause leer = gesetzliche Pause automatisch.</p>
                        <div class="flex items-center justify-between gap-2 pt-2">
                            <button type="button" x-show="vorhanden" @click="von = ''; bis = ''; $nextTick(() => $el.form.submit())"
                                    class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50">Entfernen</button>
                            <div class="ml-auto flex gap-2">
                                <button type="button" @click="offen = false" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Speichern</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>

    @if ($sichtbar && $planer)
        @php
            $hinweise = collect($pruefung['personen'])->filter(fn ($p) => $p['warnungen']);
            $zellen = collect($pruefung['zellen'])->filter();
        @endphp
        @if ($hinweise->isNotEmpty() || $zellen->isNotEmpty())
            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <div class="font-semibold">Hinweise</div>
                <ul class="mt-2 space-y-1">
                    @foreach ($hinweise as $uid => $h)
                        @foreach ($h['warnungen'] as $w)
                            <li><strong>{{ $personen[$uid]['user']->name }}:</strong> {{ $w }}</li>
                        @endforeach
                    @endforeach
                    @foreach ($zellen as $uid => $tageWarn)
                        @foreach ($tageWarn as $d => $liste)
                            <li><strong>{{ $personen[$uid]['user']->name }}, {{ \Illuminate\Support\Carbon::parse($d)->isoFormat('dd DD.MM.') }}:</strong> {{ implode(' · ', $liste) }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @once
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('schichtDialog', () => ({
                    offen: false, vorhanden: false, user: '', name: '', datum: '', tag: '', von: '', bis: '', pause: '', notiz: '',
                    oeffne(user, name, datum, tag, von, bis, pause, notiz) {
                        Object.assign(this, { offen: true, vorhanden: !! von, user, name, datum, tag, von, bis, pause, notiz });
                    },
                }));
            });
        </script>
    @endonce
</x-app-layout>
