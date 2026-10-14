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
                    Noch kein Plan für diesen Monat: „Vorschlag erstellen" oder mit + von Hand eintragen.
                </p>
            @endif
            @php
                $dauer = fn ($m) => \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m);
                $vz = fn ($m) => \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m, true);
                // Feste Farbe je Person (vollständige Klassennamen, damit Tailwind sie findet).
                $farben = [
                    'bg-indigo-100 text-indigo-900', 'bg-emerald-100 text-emerald-900', 'bg-rose-100 text-rose-900',
                    'bg-sky-100 text-sky-900', 'bg-violet-100 text-violet-900', 'bg-teal-100 text-teal-900',
                    'bg-orange-100 text-orange-900', 'bg-lime-100 text-lime-900',
                ];
                $farbe = [];
                foreach (array_keys($personen) as $nr => $uid) {
                    $farbe[$uid] = $farben[$nr % count($farben)];
                }
                $kurz = fn ($name) => \Illuminate\Support\Str::of($name)->explode(' ')->first();
            @endphp

            {{-- Personen: Farbe, Monat geplant / Soll --}}
            <div class="flex flex-wrap gap-2 border-b border-gray-200 px-4 py-3">
                @foreach ($personen as $uid => $p)
                    @php $pr = $pruefung['personen'][$uid]; $diff = $pr['geplant'] - $pr['ziel']; @endphp
                    <div class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs {{ $farbe[$uid] }} {{ $uid === $ich ? 'ring-2 ring-indigo-400' : '' }}"
                         title="{{ $pr['gutschrift'] ? 'Feiertage gutgeschrieben: '.$dauer($pr['gutschrift']).' Std. – sie mindern das Soll.' : '' }}">
                        <span class="font-semibold">{{ $p['user']->name }}</span>
                        @if ($p['aushilfe'])
                            <span>{{ $dauer($pr['geplant']) }}{{ $pr['budget'] !== null ? ' / max. '.$dauer($pr['budget']) : '' }} · Aushilfe</span>
                        @else
                            <span>{{ $dauer($pr['geplant']) }} / {{ $dauer($pr['ziel']) }}</span>
                            @if (abs($diff) >= 15)
                                <span class="font-semibold {{ $diff > 0 ? 'text-amber-700' : 'text-red-600' }}">{{ $vz($diff) }}</span>
                            @endif
                            @if ($pr['gutschrift'])<span class="opacity-70">Feiertag −{{ $dauer($pr['gutschrift']) }}</span>@endif
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="p-2 sm:p-4">
                {{-- Wochentage (nur breite Bildschirme) --}}
                <div class="hidden gap-1 pb-1 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 lg:grid lg:grid-cols-[3.5rem_repeat(7,minmax(0,1fr))_9rem]">
                    <div>KW</div>
                    @foreach (\Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE as $name)
                        <div>{{ $name }}</div>
                    @endforeach
                    <div>Summe</div>
                </div>

                <div class="space-y-3 lg:space-y-1">
                    @foreach ($wochen as $woche)
                        @php
                            $ersterTag = $tage[$woche[0]]['datum'];
                            $ende = end($woche);
                            $nr = $ersterTag->isoWeek;
                        @endphp
                        <div class="rounded-lg border border-gray-200 lg:rounded-none lg:border-0">
                            <div class="border-b border-gray-200 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-700 lg:hidden">KW {{ $nr }}</div>
                            <div class="grid grid-cols-1 gap-1 p-1 sm:grid-cols-2 lg:grid-cols-[3.5rem_repeat(7,minmax(0,1fr))_9rem] lg:p-0">
                                {{-- Kalenderwoche am Wochenanfang (breite Bildschirme; schmal steht sie oben drüber) --}}
                                <div class="hidden items-start justify-center rounded-md bg-gray-50 pt-1.5 text-sm font-semibold text-gray-600 lg:flex">KW {{ $nr }}</div>

                                {{-- Leere Felder vor dem Monatsersten --}}
                                @for ($leer = 1; $leer < $ersterTag->dayOfWeekIso; $leer++)
                                    <div class="hidden rounded-md bg-gray-50/60 lg:block"></div>
                                @endfor

                                @foreach ($woche as $d)
                                    @php
                                        $t = $tage[$d];
                                        $pt = $pruefung['tage'][$d];
                                        $heute = $t['datum']->isToday();
                                        $hier = collect($personen)->map(fn ($p, $uid) => $schichten[$uid][$d] ?? null)->filter()->sortBy('von');
                                        $weg = collect($personen)->filter(fn ($p) => isset($p['abwesend'][$d]));
                                        $fehlt = implode(' · ', array_merge(array_map(fn ($x) => 'Mindestens: '.$x, $pt['fehlt']), array_map(fn ($x) => 'Optional: '.$x, $pt['optional'])));
                                    @endphp
                                    <div class="flex flex-col rounded-md border p-1.5 lg:min-h-28 {{ $t['offen'] ? 'border-gray-200 bg-white' : 'border-gray-100 bg-gray-50' }} {{ $heute ? 'ring-2 ring-indigo-400' : '' }}">
                                        <div class="mb-1 flex items-start justify-between gap-1">
                                            <div class="leading-tight">
                                                <span class="text-sm font-semibold {{ $t['offen'] ? 'text-gray-800' : 'text-gray-400' }}">{{ $t['datum']->day }}.</span>
                                                <span class="text-xs text-gray-500 lg:hidden">{{ $t['datum']->isoFormat('dddd') }}</span>
                                                @if ($t['hinweis'])
                                                    <div class="text-[11px] {{ $t['offen'] ? 'text-indigo-600' : 'text-red-500' }}">{{ $t['hinweis'] }}</div>
                                                @elseif (! $t['offen'])
                                                    <div class="text-[11px] text-gray-400">geschlossen</div>
                                                @endif
                                            </div>
                                            @if ($t['offen'])
                                                <span title="{{ $fehlt ?: 'besetzt' }}">
                                                    @if ($pt['fehlt'])
                                                        <span class="inline-block rounded bg-red-500 px-1.5 text-[11px] font-bold text-white">!</span>
                                                    @elseif ($pt['optional'])
                                                        <span class="inline-block rounded bg-amber-300 px-1.5 text-[11px] text-white">~</span>
                                                    @else
                                                        <span class="text-sm text-green-600">✓</span>
                                                    @endif
                                                </span>
                                            @endif
                                        </div>

                                        <div class="flex flex-1 flex-col gap-1">
                                            @foreach ($hier as $uid => $s)
                                                @php
                                                    $p = $personen[$uid];
                                                    $warn = $pruefung['zellen'][$uid][$d] ?? [];
                                                    $ue = $pruefung['ueberstunden'][$uid][$d] ?? 0;
                                                    $titel = implode(' · ', array_filter([$p['user']->name.' '.$s->von.'–'.$s->bis.($s->pause ? ', Pause '.$s->pause.' Min.' : ''), ...$warn, $ue ? 'Überstunden: '.$dauer($ue) : null, $s->notiz]));
                                                @endphp
                                                @if ($planer)
                                                    <button type="button" title="{{ $titel }}"
                                                            @click="oeffne({{ $uid }}, @js($p['user']->name), '{{ $d }}', @js($t['datum']->isoFormat('dd DD.MM.')), @js($t['datum']->isoFormat('dddd')), '{{ $s->von }}', '{{ $s->bis }}', '{{ $s->pause }}', @js($s->notiz ?? ''))"
                                                            class="flex w-full flex-wrap items-center justify-between gap-x-1 rounded px-1.5 py-0.5 text-left text-xs {{ $farbe[$uid] }} {{ $warn ? 'ring-2 ring-red-500' : '' }} {{ $uid === $ich ? 'font-semibold' : '' }}">
                                                        <span class="truncate">{{ $kurz($p['user']->name) }}</span>
                                                        <span class="whitespace-nowrap">{{ $s->von }}–{{ $s->bis }}</span>
                                                        @if ($ue)<span class="basis-full text-right"><span class="rounded bg-amber-400 px-1 text-[10px] font-bold text-white">Ü +{{ $dauer($ue) }}</span></span>@endif
                                                    </button>
                                                @else
                                                    <div title="{{ $titel }}"
                                                         class="flex flex-wrap items-center justify-between gap-x-1 rounded px-1.5 py-0.5 text-xs {{ $farbe[$uid] }} {{ $uid === $ich ? 'font-semibold' : '' }}">
                                                        <span class="truncate">{{ $kurz($p['user']->name) }}</span>
                                                        <span class="whitespace-nowrap">{{ $s->von }}–{{ $s->bis }}</span>
                                                        @if ($ue)<span class="basis-full text-right"><span class="rounded bg-amber-400 px-1 text-[10px] font-bold text-white">Ü +{{ $dauer($ue) }}</span></span>@endif
                                                    </div>
                                                @endif
                                            @endforeach
                                            @foreach ($weg as $uid => $p)
                                                <div class="truncate rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-700" title="{{ $p['abwesend'][$d] }}">
                                                    {{ $kurz($p['user']->name) }}: {{ $p['abwesend'][$d] }}
                                                </div>
                                            @endforeach
                                            @if ($planer)
                                                <button type="button" @click="neu('{{ $d }}', @js($t['datum']->isoFormat('dd DD.MM.')), @js($t['datum']->isoFormat('dddd')))"
                                                        class="mt-auto self-start rounded px-1.5 text-sm leading-5 text-gray-400 hover:bg-gray-100 hover:text-indigo-600" title="Schicht eintragen">+</button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach

                                {{-- Leere Felder nach dem Monatsletzten --}}
                                @for ($leer = $tage[$ende]['datum']->dayOfWeekIso; $leer < 7; $leer++)
                                    <div class="hidden rounded-md bg-gray-50/60 lg:block"></div>
                                @endfor

                                {{-- Wochensumme: Plan / Soll je Person --}}
                                <div class="rounded-md bg-gray-100 p-1.5 text-xs sm:col-span-2 lg:col-span-1">
                                    <div class="mb-1 font-semibold text-gray-700">Summe</div>
                                    @foreach ($personen as $uid => $p)
                                        @php $w = $pruefung['wochen'][$uid][$ende] ?? ['ist' => 0, 'soll' => 0]; $diff = $w['ist'] - $w['soll']; @endphp
                                        @if ($w['ist'] || $w['soll'])
                                            <div class="py-0.5 leading-tight">
                                                <div class="truncate text-gray-600">{{ $p['user']->name }}</div>
                                                <div class="whitespace-nowrap text-gray-800">
                                                    {{ $dauer($w['ist']) }}@if (! $p['aushilfe'])<span class="text-gray-500"> / {{ $dauer($w['soll']) }}</span>@endif
                                                    @if (! $p['aushilfe'] && abs($diff) >= 15)
                                                        <span class="font-semibold {{ $diff > 0 ? 'text-amber-700' : 'text-red-600' }}">{{ $vz($diff) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap gap-4 border-t border-gray-200 px-4 py-2 text-xs text-gray-500">
                <span><span class="text-green-600">✓</span> besetzt</span>
                <span><span class="inline-block rounded bg-amber-300 px-1 text-white">~</span> optionale Besetzung fehlt</span>
                <span><span class="inline-block rounded bg-red-500 px-1 font-bold text-white">!</span> unter Mindestbesetzung</span>
                <span><span class="inline-block rounded bg-amber-400 px-1 font-bold text-white">Ü</span> Überstunden der Woche</span>
                <span>Σ = Plan / Soll der Woche (Feiertage und Abwesenheit gutgeschrieben)</span>
                @if ($planer)<span>Schicht anklicken zum Ändern, + zum Eintragen</span>@endif
            </div>
        @endif

        @if ($planer && $sichtbar)
            {{-- Dialog: Schicht eintragen / ändern / entfernen --}}
            <div x-show="offen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="offen = false">
                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl" @click.outside="offen = false">
                    <h3 class="text-base font-semibold text-gray-800"><span x-text="vorhanden ? name : 'Neue Schicht'"></span> · <span x-text="tag"></span></h3>
                    <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.schicht', $abteilung) }}" class="mt-4 space-y-3">
                        @csrf
                        <template x-if="vorhanden">
                            <input type="hidden" name="user_id" :value="user">
                        </template>
                        <template x-if="! vorhanden">
                            <label class="block text-sm"><span class="text-gray-600">Person</span>
                                <select name="user_id" x-model="user" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">bitte wählen …</option>
                                    @foreach ($personen as $uid => $p)
                                        <option value="{{ $uid }}">{{ $p['user']->name }}{{ $p['aushilfe'] ? ' (Aushilfe)' : '' }}</option>
                                    @endforeach
                                </select></label>
                        </template>
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
                    neu(datum, tag, wochentag) {
                        Object.assign(this, { offen: true, alle: false, vorhanden: false, user: '', name: '', datum, tag, wochentag, von: '', bis: '', pause: '', notiz: '' });
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
