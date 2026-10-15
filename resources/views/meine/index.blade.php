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

            @if ($webStempeln)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div class="text-xs uppercase tracking-wide text-gray-400">PC-Programm</div>
                        <span class="text-xs text-gray-400">fragt beim Start „Arbeitszeit starten?“ und erinnert an Pause und Feierabend – gebucht wird nur, was du anklickst</span>
                    </div>
                    @if (session('zeit_geraet'))
                        @php $neu = session('zeit_geraet'); @endphp
                        @php $code = rtrim($neu['adresse'], '/').'#'.$neu['schluessel']; @endphp
                        <div class="mt-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900"
                             x-data="{ kopiert: false, kopieren() { navigator.clipboard?.writeText(this.$refs.code.value).then(() => this.kopiert = true); this.$refs.code.select(); } }">
                            <div class="font-semibold">„{{ $neu['name'] }}“ verbinden – diesen Verbindungscode ins Programm einfügen:</div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <input type="text" readonly x-ref="code" value="{{ $code }}" @focus="$el.select()"
                                       class="min-w-64 flex-1 rounded-lg border-indigo-200 bg-white font-mono text-xs">
                                <button type="button" @click="kopieren()" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700"
                                        x-text="kopiert ? 'Kopiert ✓' : 'Kopieren'">Kopieren</button>
                            </div>
                            <div class="mt-1 text-xs text-indigo-700">Wird nur jetzt angezeigt. Geht er verloren: Gerät trennen und neu verbinden.</div>
                        </div>
                    @endif
                    <div class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($geraete as $g)
                            <div class="flex items-center justify-between gap-3 py-1.5">
                                <span class="text-gray-800">{{ $g->name }}</span>
                                <span class="text-xs text-gray-400">{{ $g->zuletzt_am ? 'zuletzt '.$g->zuletzt_am->diffForHumans() : 'noch nie verbunden' }}</span>
                                <form method="POST" action="{{ route('module.zeiterfassung.meine.geraet.entfernen', $g) }}"
                                      data-bestaetigen="Das Programm auf diesem Gerät kann danach nicht mehr stempeln." data-titel="„{{ $g->name }}“ trennen?" data-knopf="Trennen">
                                    @csrf
                                    <button type="submit" class="rounded-lg px-2 py-1 text-xs text-red-600 hover:bg-red-50">trennen</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                    @php $einklappen = $geraete->isNotEmpty() && ! session('zeit_geraet'); @endphp
                    <details @if (! $einklappen) open @endif class="group mt-2">
                        <summary class="{{ $einklappen ? '' : 'hidden' }} cursor-pointer list-none text-sm text-indigo-600 hover:underline">
                            <span class="group-open:hidden">Weitere Geräte einrichten …</span><span class="hidden group-open:inline">Weitere Geräte einrichten – zuklappen</span>
                        </summary>
                        <ol class="mt-2 list-inside list-decimal space-y-0.5 text-sm text-gray-600">
                            <li>Programm herunterladen und starten (Windows).
                                @if ($programmDa)
                                    <a href="{{ route('module.zeiterfassung.meine.programm') }}" class="ml-1 inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700"><i class="bx bx-download"></i> Für Windows herunterladen</a>
                                @else
                                    <span class="text-gray-400">(noch nicht verfügbar)</span>
                                @endif
                            </li>
                            <li>Unten einen Gerätenamen eingeben und „Gerät verbinden“ klicken.</li>
                            <li>Den angezeigten Verbindungscode kopieren und im Programm einfügen – fertig. Es startet danach mit Windows.</li>
                        </ol>
                        <p class="mt-1 text-xs text-gray-400">Windows warnt beim ersten Start evtl. „Der Computer wurde durch Windows geschützt“: auf „Weitere Informationen“ und „Trotzdem ausführen“ klicken.</p>
                        <form method="POST" action="{{ route('module.zeiterfassung.meine.geraet') }}" class="mt-2 flex flex-wrap gap-2">
                            @csrf
                            <input type="text" name="name" required maxlength="150" placeholder="Gerätename, z. B. Laptop Büro" class="min-w-48 flex-1 rounded-lg border-gray-300 text-sm">
                            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Gerät verbinden</button>
                        </form>
                    </details>
                </div>
            @endif

            @if ($teamsMoeglich && $teilnehmer)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Teams-Status</div>
                        <span class="text-xs text-gray-400">freiwillig – nur dein eigener Status, jederzeit wieder trennbar</span>
                    </div>
                    @if ($teamsGekoppelt)
                        <p class="mt-1 text-sm text-gray-700">
                            Gekoppelt{{ $teamsKonto ? ' mit '.$teamsKonto : '' }}: <strong>Gehen</strong> stellt Teams auf „Offline“, <strong>Pause</strong> auf „Bin gleich zurück“, <strong>Kommen</strong> bzw. Pause beenden wieder auf „Verfügbar“.
                            Stellst du Teams selbst auf „Als offline anzeigen“ bzw. wieder auf verfügbar, fragt das PC-Programm, ob die Arbeitszeit enden bzw. beginnen soll.
                        </p>
                        @if ($teamsFehler)
                            <p class="mt-1 text-xs text-red-600">Zuletzt: {{ $teamsFehler }}</p>
                        @endif
                        <form method="POST" action="{{ route('module.zeiterfassung.meine.teams.trennen') }}" class="mt-2"
                              data-bestaetigen="Teams wird danach nicht mehr mit deiner Arbeitszeit abgeglichen." data-titel="Teams entkoppeln?" data-knopf="Entkoppeln">
                            @csrf
                            <button type="submit" class="text-sm text-red-600 hover:underline">Teams entkoppeln</button>
                        </form>
                    @else
                        <p class="mt-1 text-sm text-gray-700">
                            Auf Wunsch stellt Gehen dein Teams auf „Offline“ und Kommen wieder auf „Verfügbar“ – und das PC-Programm fragt nach,
                            wenn du Teams selbst umstellst. Dazu einmal mit deinem Microsoft-Konto zustimmen (Berechtigung: eigenen Status lesen und setzen).
                        </p>
                        <a href="{{ route('module.zeiterfassung.meine.teams') }}" class="mt-2 inline-flex rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Teams koppeln</a>
                    @endif
                </div>
            @endif

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
