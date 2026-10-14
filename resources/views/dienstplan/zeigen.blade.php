<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Dienstplan {{ $abteilung->name }}</h1>
            <div class="flex flex-wrap gap-3 text-sm">
                <a href="{{ route('module.zeiterfassung.dienstplan.index') }}" class="text-indigo-600 hover:underline">alle Abteilungen</a>
                @if ($planer)
                    <a href="{{ route('module.zeiterfassung.dienstplan.abteilung', $abteilung) }}" class="text-indigo-600 hover:underline">Einstellungen zu {{ $abteilung->name }}</a>
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
            @if ($planer && ! $plan)
                <p class="border-b border-gray-200 px-4 py-2 text-sm text-gray-500">
                    Noch kein Plan für diesen Monat: „Vorschlag erstellen" oder Zellen anklicken und von Hand eintragen.
                </p>
            @endif
            @php
                $dauer = fn ($m) => \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m);
                $kw = [];
                $start = null;
                foreach ($tage as $d => $t) {
                    $start ??= $d;
                    if (in_array($d, $wochenEnde, true)) {
                        $kw[] = ['nr' => $t['datum']->isoWeek, 'tage' => count(array_filter(array_keys($tage), fn ($x) => $x >= $start && $x <= $d)) + 1];
                        $start = null;
                    }
                }
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500">
                            <th class="sticky left-0 z-10 bg-gray-50 px-3 pt-2"></th>
                            @foreach ($kw as $w)
                                <th colspan="{{ $w['tage'] }}" class="border-l border-gray-200 px-1 pt-2 text-left text-[11px] font-semibold text-gray-600">KW {{ $w['nr'] }}</th>
                            @endforeach
                            <th colspan="2"></th>
                        </tr>
                        <tr class="border-b border-gray-200 bg-gray-50 text-gray-500">
                            <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left font-semibold">Person</th>
                            @foreach ($tage as $d => $t)
                                <th class="min-w-12 px-0.5 py-2 text-center font-normal {{ $t['offen'] ? '' : 'text-gray-300' }} {{ $t['datum']->isToday() ? 'font-bold text-indigo-600' : '' }} {{ $t['datum']->dayOfWeekIso === 1 || $loop->first ? 'border-l border-gray-200' : '' }}"
                                    title="{{ $t['hinweis'] }}">
                                    {{ $t['datum']->isoFormat('dd') }}<br>{{ $t['datum']->day }}
                                    @if ($t['hinweis'])<div class="truncate text-[9px] {{ $t['offen'] ? 'text-indigo-500' : 'text-red-400' }}">{{ mb_substr($t['hinweis'], 0, 6) }}</div>@endif
                                </th>
                                @if (in_array($d, $wochenEnde, true))
                                    <th class="min-w-14 bg-gray-100 px-1 py-2 text-center font-semibold text-gray-600">Σ KW<br>{{ $t['datum']->isoWeek }}</th>
                                @endif
                            @endforeach
                            <th class="px-2 py-2 text-right font-semibold">Monat</th>
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
                                        $ue = $pruefung['ueberstunden'][$uid][$d] ?? 0;
                                        $titel = implode(' · ', array_filter([$weg, ...$warn, $ue ? 'Überstunden: '.$dauer($ue) : null, $s?->notiz]));
                                    @endphp
                                    <td class="px-0.5 py-1 text-center align-top {{ $t['offen'] ? '' : 'bg-gray-50' }} {{ $t['datum']->dayOfWeekIso === 1 || $loop->first ? 'border-l border-gray-200' : '' }}">
                                        @if ($planer)
                                            <button type="button"
                                                    @click="oeffne({{ $uid }}, @js($p['user']->name), '{{ $d }}', @js($t['datum']->isoFormat('dd DD.MM.')), @js($t['datum']->isoFormat('dddd')), '{{ $s?->von }}', '{{ $s?->bis }}', '{{ $s ? $s->pause : '' }}', @js($s?->notiz ?? ''))"
                                                    title="{{ $titel }}"
                                                    class="block w-full rounded px-0.5 py-0.5 leading-tight {{ $s ? ($warn ? 'bg-red-100 text-red-800' : ($ue ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800')) : ($weg ? 'bg-sky-50 text-sky-700' : 'text-gray-300 hover:bg-gray-100') }}">
                                                @if ($s){{ $s->von }}<br>{{ $s->bis }}@elseif ($weg){{ mb_substr($weg, 0, 1) }}@else&nbsp;<br>&nbsp;@endif
                                                @if ($ue)<span class="block text-[9px] font-semibold">Ü +{{ $dauer($ue) }}</span>@endif
                                            </button>
                                        @else
                                            <div title="{{ $titel }}"
                                                 class="rounded px-0.5 py-0.5 leading-tight {{ $s ? ($ue ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800') : ($weg ? 'bg-sky-50 text-sky-700' : '') }}">
                                                @if ($s){{ $s->von }}<br>{{ $s->bis }}@elseif ($weg){{ mb_substr($weg, 0, 1) }}@endif
                                                @if ($ue)<span class="block text-[9px] font-semibold">Ü +{{ $dauer($ue) }}</span>@endif
                                            </div>
                                        @endif
                                    </td>
                                    @if (in_array($d, $wochenEnde, true))
                                        @php $w = $pruefung['wochen'][$uid][$d] ?? ['ist' => 0, 'soll' => 0]; $diff = $w['ist'] - $w['soll']; @endphp
                                        <td class="whitespace-nowrap bg-gray-100 px-1 py-1 text-center align-top leading-tight"
                                            title="Plan {{ $dauer($w['ist']) }} · Soll {{ $dauer($w['soll']) }}">
                                            <span class="font-semibold text-gray-800">{{ $dauer($w['ist']) }}</span>
                                            @if (! $p['aushilfe'])
                                                <br><span class="text-gray-500">/ {{ $dauer($w['soll']) }}</span>
                                                @if (abs($diff) >= 15)
                                                    <br><span class="font-semibold {{ $diff > 0 ? 'text-amber-700' : 'text-red-600' }}">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($diff, true) }}</span>
                                                @endif
                                            @endif
                                        </td>
                                    @endif
                                @endforeach
                                <td class="whitespace-nowrap px-2 py-1.5 text-right text-sm text-gray-800">{{ $dauer($pr['geplant']) }}</td>
                                <td class="whitespace-nowrap px-2 py-1.5 text-right text-sm text-gray-500"
                                    title="{{ $pr['gutschrift'] ? 'Feiertage gutgeschrieben: '.$dauer($pr['gutschrift']).' Std. – sie mindern das Soll.' : '' }}">
                                    @if ($p['aushilfe'])
                                        {{ $pr['budget'] !== null ? 'max. '.$dauer($pr['budget']) : '–' }}
                                    @else
                                        {{ $dauer($pr['ziel']) }}
                                        @if ($pr['gutschrift'])<div class="text-[10px] text-gray-400">Feiertag −{{ $dauer($pr['gutschrift']) }}</div>@endif
                                        @if (abs($pr['geplant'] - $pr['ziel']) >= 15)
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
                                <td class="px-0.5 py-1 text-center {{ $t['datum']->dayOfWeekIso === 1 || $loop->first ? 'border-l border-gray-200' : '' }}" title="{{ implode(' · ', array_merge(array_map(fn ($x) => 'Mindestens: '.$x, $pt['fehlt']), array_map(fn ($x) => 'Optional: '.$x, $pt['optional']))) }}">
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
                                @if (in_array($d, $wochenEnde, true))
                                    <td class="bg-gray-100"></td>
                                @endif
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
                <span><span class="inline-block rounded bg-amber-100 px-1 text-amber-800">Ü</span> Überstunden der Woche</span>
                <span><span class="inline-block rounded bg-sky-50 px-1 text-sky-700">U</span> abwesend</span>
                <span>Σ KW = Plan / Soll der Woche (Feiertage und Abwesenheit gutgeschrieben)</span>
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
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="alle" value="1" x-model="alle" class="rounded border-gray-300 text-indigo-600">
                            <span>Für alle <span x-text="wochentag"></span>e dieses Monats übernehmen</span>
                        </label>
                        <p class="text-xs text-gray-400">Pause leer = gesetzliche Pause automatisch. Geschlossene Tage und Abwesenheiten werden übersprungen.</p>
                        <div class="flex items-center justify-between gap-2 pt-2">
                            <button type="button" x-show="vorhanden" @click="entfernen($el.form)"
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
                    offen: false, vorhanden: false, alle: false, user: '', name: '', datum: '', tag: '', wochentag: '', von: '', bis: '', pause: '', notiz: '',
                    oeffne(user, name, datum, tag, wochentag, von, bis, pause, notiz) {
                        Object.assign(this, { offen: true, alle: false, vorhanden: !! von, user, name, datum, tag, wochentag, von, bis, pause, notiz });
                    },
                    async entfernen(form) {
                        if (this.alle && window.bestaetige && ! await window.bestaetige(
                            `Alle Schichten von ${this.name} an den ${this.wochentag}en dieses Monats werden entfernt.`,
                            { titel: 'Alle entfernen?', knopf: 'Entfernen', gefaehrlich: true })) return;
                        this.von = ''; this.bis = '';
                        await this.$nextTick();
                        form.submit();
                    },
                }));
            });
        </script>
    @endonce
</x-app-layout>
