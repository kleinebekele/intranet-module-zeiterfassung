<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Abteilung {{ $abteilung->name }} einrichten</h1>
            <a href="{{ route('module.zeiterfassung.dienstplan.zeigen', $abteilung) }}" class="text-sm text-indigo-600 hover:underline">zum Dienstplan</a>
        </div>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    @php
        $tageNamen = \Intranet\Modules\Zeiterfassung\Models\Zeitmodell::TAGE;
        $dauer = fn ($m) => $m === null ? '' : \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m);
    @endphp

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- Besetzung --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Besetzung</h2>
            <p class="mt-1 text-sm text-gray-500">
                Wann die Abteilung wie besetzt sein muss. <strong>Mindestens</strong> plant der Vorschlag immer ein (notfalls mit Überstunden),
                <strong>optional</strong> nur, solange Stunden bzw. Aushilfen-Budget übrig sind. Überlappende Fenster: es gilt der höhere Wert,
                z. B. Sa 9–18 Uhr mindestens 1 und Sa 11–15 Uhr mindestens 2.
            </p>
            <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.abteilung.besetzung', $abteilung) }}" class="mt-4">
                @csrf
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-2 py-2 font-semibold">Tag</th>
                                <th class="px-2 py-2 font-semibold">Von</th>
                                <th class="px-2 py-2 font-semibold">Bis</th>
                                <th class="px-2 py-2 font-semibold">Mindestens</th>
                                <th class="px-2 py-2 font-semibold">Optional zusätzlich</th>
                                <th class="px-2 py-2 font-semibold">Löschen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($abteilung->besetzung as $b)
                                <tr>
                                    <td class="px-2 py-1.5 font-medium text-gray-800">{{ $tageNamen[$b->wochentag] }}</td>
                                    <td class="px-2 py-1.5"><input type="time" name="fenster[{{ $b->id }}][von]" value="{{ $b->von }}" required class="rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="time" name="fenster[{{ $b->id }}][bis]" value="{{ $b->bis }}" required class="rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="number" min="0" max="50" name="fenster[{{ $b->id }}][mindestens]" value="{{ $b->mindestens }}" class="w-20 rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="number" min="0" max="50" name="fenster[{{ $b->id }}][optional]" value="{{ $b->optional }}" class="w-20 rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="checkbox" name="fenster[{{ $b->id }}][loeschen]" value="1" class="rounded border-gray-300 text-red-600"></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-2 py-3 text-gray-400">Noch keine Zeitfenster.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 rounded-lg bg-gray-50 p-3">
                    <div class="text-sm font-medium text-gray-700">Zeitfenster hinzufügen</div>
                    <div class="mt-2 flex flex-wrap items-end gap-3">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($tageNamen as $nr => $name)
                                <label class="flex items-center gap-1 text-sm text-gray-700">
                                    <input type="checkbox" name="neu[tage][]" value="{{ $nr }}" class="rounded border-gray-300 text-indigo-600"> {{ $name }}
                                </label>
                            @endforeach
                        </div>
                        <label class="text-sm"><span class="block text-gray-600">Von</span><input type="time" name="neu[von]" class="rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-sm"><span class="block text-gray-600">Bis</span><input type="time" name="neu[bis]" class="rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-sm"><span class="block text-gray-600">Mindestens</span><input type="number" min="0" max="50" name="neu[mindestens]" value="1" class="w-20 rounded-lg border-gray-300 text-sm"></label>
                        <label class="text-sm"><span class="block text-gray-600">Optional</span><input type="number" min="0" max="50" name="neu[optional]" value="0" class="w-20 rounded-lg border-gray-300 text-sm"></label>
                    </div>
                </div>
                <div class="mt-3 flex justify-end">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Besetzung speichern</button>
                </div>
            </form>
        </div>

        {{-- Personen --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Personen</h2>
            <p class="mt-1 text-sm text-gray-500">
                Wochenstunden leer = aus dem Zeitmodell der Person (Team → Person). <strong>Aushilfen</strong> springen nur bei Lücken und
                für die optionale Besetzung ein, höchstens bis zu ihrem Monatsbudget (z. B. Minijob-Grenze ÷ Stundenlohn).
            </p>
            <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.abteilung.personen', $abteilung) }}" class="mt-4">
                @csrf
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-2 py-2 font-semibold">Person</th>
                                <th class="px-2 py-2 font-semibold">Aushilfe</th>
                                <th class="px-2 py-2 font-semibold">Wochenstunden</th>
                                <th class="px-2 py-2 font-semibold">Budget / Monat</th>
                                <th class="px-2 py-2 font-semibold">Tage / Woche</th>
                                <th class="px-2 py-2 font-semibold">Feste freie Tage</th>
                                <th class="px-2 py-2 font-semibold">Entfernen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($abteilung->personen as $p)
                                @php
                                    $m = $modelle[$p->id] ?? null;
                                    $frei = array_map('intval', array_filter(explode(',', (string) $p->pivot->freie_tage)));
                                @endphp
                                <tr>
                                    <td class="px-2 py-1.5">
                                        <div class="font-medium text-gray-800">{{ $p->name }}</div>
                                        <div class="text-xs text-gray-400">
                                            {{ $m ? 'Zeitmodell: '.$dauer($m->wochenMinuten()).' Std. an '.$m->arbeitstageJeWoche().' Tagen' : 'kein Zeitmodell' }}
                                        </div>
                                    </td>
                                    <td class="px-2 py-1.5"><input type="checkbox" name="personen[{{ $p->id }}][aushilfe]" value="1" @checked($p->pivot->aushilfe) class="rounded border-gray-300 text-indigo-600"></td>
                                    <td class="px-2 py-1.5"><input type="text" name="personen[{{ $p->id }}][wochenstunden]" value="{{ $dauer($p->pivot->wochenstunden) }}" placeholder="{{ $m ? $dauer($m->wochenMinuten()) : '' }}" class="w-24 rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="text" name="personen[{{ $p->id }}][max_monat]" value="{{ $dauer($p->pivot->max_monat) }}" placeholder="unbegrenzt" class="w-24 rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="number" min="1" max="6" name="personen[{{ $p->id }}][max_tage_woche]" value="{{ $p->pivot->max_tage_woche }}" placeholder="{{ $m?->arbeitstageJeWoche() ?: 5 }}" class="w-20 rounded-lg border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5">
                                        <div class="flex min-w-72 flex-wrap gap-1.5">
                                            @foreach ($tageNamen as $nr => $name)
                                                <label class="flex items-center gap-0.5 text-xs text-gray-600">
                                                    <input type="checkbox" name="personen[{{ $p->id }}][freie_tage][]" value="{{ $nr }}" @checked(in_array($nr, $frei, true)) class="rounded border-gray-300 text-indigo-600">{{ $name }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="px-2 py-1.5"><input type="checkbox" name="personen[{{ $p->id }}][entfernen]" value="1" class="rounded border-gray-300 text-red-600"></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-2 py-3 text-gray-400">Noch niemand.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <select name="neu" class="min-w-64 rounded-lg border-gray-300 text-sm">
                        <option value="">Person hinzufügen …</option>
                        @foreach ($benutzer->whereNotIn('id', $abteilung->personen->pluck('id')) as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Personen speichern</button>
                </div>
            </form>
        </div>

        {{-- Sondertage --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5" x-data="{ art: 'geschlossen' }">
            <h2 class="text-base font-semibold text-gray-800">Sondertage</h2>
            <p class="mt-1 text-sm text-gray-500">
                Weichen von der Besetzung ab: geschlossen (Rosenmontag, Betriebsferien, Inventur) oder mit eigenen Zeiten
                (Heiligabend, Silvester, verkaufsoffener Sonntag – geht auch an einem Feiertag).
            </p>
            <div class="mt-3 divide-y divide-gray-100 text-sm">
                @forelse ($abteilung->sondertage as $s)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="w-28 text-gray-800">{{ $s->datum->isoFormat('dd DD.MM.YYYY') }}</span>
                        <span class="flex-1 text-gray-700">{{ $s->bezeichnung }}</span>
                        <span class="text-xs text-gray-500">{{ $s->geschlossen ? 'geschlossen' : $s->von.'–'.$s->bis.' · mind. '.$s->mindestens.($s->optional ? ' (+'.$s->optional.')' : '') }}</span>
                        <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.abteilung.sondertag.entfernen', [$abteilung, $s]) }}">
                            @csrf
                            <button type="submit" class="rounded-lg px-2 py-1 text-xs text-red-600 hover:bg-red-50">entfernen</button>
                        </form>
                    </div>
                @empty
                    <p class="py-2 text-gray-400">Keine.</p>
                @endforelse
            </div>
            <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.abteilung.sondertag', $abteilung) }}" class="mt-3 space-y-3 border-t border-gray-100 pt-3">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="text-gray-600">Datum</span><input type="date" name="datum" required class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                    <label class="block text-sm"><span class="text-gray-600">bis (optional)</span><input type="date" name="bis_datum" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                </div>
                <input type="text" name="bezeichnung" required maxlength="150" placeholder="Bezeichnung, z. B. Heiligabend" class="w-full rounded-lg border-gray-300 text-sm">
                <div class="flex gap-4 text-sm">
                    <label class="flex items-center gap-1"><input type="radio" name="art" value="geschlossen" x-model="art" class="border-gray-300 text-indigo-600"> geschlossen</label>
                    <label class="flex items-center gap-1"><input type="radio" name="art" value="offen" x-model="art" class="border-gray-300 text-indigo-600"> eigene Zeiten</label>
                </div>
                <div x-show="art === 'offen'" x-cloak class="flex flex-wrap gap-3">
                    <label class="text-sm"><span class="block text-gray-600">Von</span><input type="time" name="von" class="rounded-lg border-gray-300 text-sm"></label>
                    <label class="text-sm"><span class="block text-gray-600">Bis</span><input type="time" name="bis" class="rounded-lg border-gray-300 text-sm"></label>
                    <label class="text-sm"><span class="block text-gray-600">Mindestens</span><input type="number" min="0" max="50" name="mindestens" value="1" class="w-20 rounded-lg border-gray-300 text-sm"></label>
                    <label class="text-sm"><span class="block text-gray-600">Optional</span><input type="number" min="0" max="50" name="optional" value="0" class="w-20 rounded-lg border-gray-300 text-sm"></label>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Eintragen</button>
                </div>
            </form>
        </div>

        {{-- Stammdaten + Feiertage --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-base font-semibold text-gray-800">Allgemein</h2>
                <form method="POST" action="{{ route('module.zeiterfassung.dienstplan.abteilung.speichern', $abteilung) }}" class="mt-3 space-y-3">
                    @csrf
                    <label class="block text-sm"><span class="text-gray-600">Name</span>
                        <input type="text" name="name" value="{{ $abteilung->name }}" required maxlength="150" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                    <label class="block text-sm"><span class="text-gray-600">Feiertage nach Bundesland</span>
                        <select name="bundesland" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            <option value="">Standard der Zeiterfassung ({{ $laender[\Intranet\Modules\Zeiterfassung\Support\Einstellungen::get('bundesland')] ?? '' }})</option>
                            @foreach ($laender as $code => $name)
                                <option value="{{ $code }}" @selected($abteilung->bundesland === $code)>{{ $name }}</option>
                            @endforeach
                        </select></label>
                    <label class="block text-sm"><span class="text-gray-600">Kürzeste Schicht (für Lücken)</span>
                        <input type="text" name="min_schicht" value="{{ $dauer($abteilung->min_schicht) }}" required class="mt-1 w-32 rounded-lg border-gray-300 text-sm"></label>
                    <label class="block text-sm"><span class="text-gray-600">Notiz</span>
                        <input type="text" name="notiz" value="{{ $abteilung->notiz }}" maxlength="500" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                    <div class="flex justify-between">
                        <button type="submit" formaction="{{ route('module.zeiterfassung.dienstplan.abteilung.entfernen', $abteilung) }}"
                                data-bestaetigen="Alle Dienstpläne dieser Abteilung werden mit gelöscht. Erfasste Arbeitszeiten bleiben erhalten."
                                data-titel="Abteilung {{ $abteilung->name }} entfernen?" data-knopf="Entfernen"
                                class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50">Abteilung entfernen</button>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Speichern</button>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-base font-semibold text-gray-800">Nächste gesetzliche Feiertage</h2>
                <p class="mt-1 text-xs text-gray-400">Werden automatisch berücksichtigt (geschlossen). Abweichend öffnen: als Sondertag mit eigenen Zeiten eintragen.</p>
                <div class="mt-2 divide-y divide-gray-100 text-sm">
                    @foreach ($feiertage as $datum => $name)
                        <div class="flex justify-between py-1.5">
                            <span class="text-gray-800">{{ \Illuminate\Support\Carbon::parse($datum)->isoFormat('dd DD.MM.YYYY') }}</span>
                            <span class="text-gray-600">{{ $name }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
