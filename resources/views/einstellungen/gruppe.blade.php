<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-gray-800">Gruppe {{ $gruppe->name() }}</h1>
            <a href="{{ route('module.zeiterfassung.einstellungen.index') }}" class="text-sm text-indigo-600 hover:underline">zurück zu den Einstellungen</a>
        </div>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    @php
        $dauer = fn ($m) => \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $m);
        $rolle = $gruppe->rolle;
        $umbenennbar = $rolle && ! $rolle->istVerwaltet() && ! $rolle->stammtAusManifest() && ! $rolle->isSystem();
    @endphp

    @if ($gruppe->leitung->isEmpty())
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Diese Gruppe hat noch keine Leitung. Bitte unten mindestens eine Person als Leitung eintragen.
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        {{-- Leitung + Name --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-base font-semibold text-gray-800">Leitung</h2>
                <p class="mt-1 text-sm text-gray-500">Sieht und korrigiert die Zeiten der Mitglieder, gibt Urlaub und Nachträge frei. Mindestens eine Person.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($gruppe->leitung->sortBy('name') as $l)
                        <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.leitung.entfernen', [$gruppe, $l]) }}"
                              class="flex items-center gap-1 rounded-full bg-indigo-100 py-1 pl-3 pr-1 text-sm text-indigo-800">
                            @csrf
                            <span>{{ $l->name }}</span>
                            <button type="submit" title="Leitung entfernen" class="rounded-full px-1.5 text-indigo-500 hover:bg-indigo-200 hover:text-indigo-900">×</button>
                        </form>
                    @empty
                        <span class="text-sm text-gray-400">Noch niemand.</span>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.leitung', $gruppe) }}" class="mt-3 flex flex-wrap gap-2">
                    @csrf
                    <select name="user_id" required class="min-w-48 flex-1 rounded-lg border-gray-300 text-sm">
                        <option value="">Leitung hinzufügen …</option>
                        @if ($mitglieder->isNotEmpty())
                            <optgroup label="Mitglieder der Gruppe">
                                @foreach ($mitglieder->whereNotIn('id', $gruppe->leitung->pluck('id')) as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                        <optgroup label="Alle anderen">
                            @foreach ($benutzer->whereNotIn('id', $mitglieder->pluck('id'))->whereNotIn('id', $gruppe->leitung->pluck('id')) as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Hinzufügen</button>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-base font-semibold text-gray-800">Gruppe</h2>
                @if ($umbenennbar)
                    <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.name', $gruppe) }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="name" value="{{ $gruppe->name() }}" required maxlength="255" class="min-w-48 flex-1 rounded-lg border-gray-300 text-sm">
                        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Umbenennen</button>
                    </form>
                @endif
                <p class="mt-2 text-xs text-gray-400">Rolle „{{ $gruppe->role_id }}“{{ $rolle?->istVerwaltet() ? ' – Mitglieder kommen aus dem Abgleich „'.$rolle->quelle.'“' : '' }}.</p>
                <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.entfernen', $gruppe) }}" class="mt-3"
                      data-bestaetigen="Die Gruppe nimmt dann nicht mehr an der Zeiterfassung teil. Die Rolle und alle erfassten Zeiten bleiben erhalten."
                      data-titel="Gruppe {{ $gruppe->name() }} entfernen?" data-knopf="Entfernen">
                    @csrf
                    <button type="submit" class="rounded-lg px-3 py-1.5 text-sm text-red-600 hover:bg-red-50">Gruppe entfernen</button>
                </form>
            </div>
        </div>

        {{-- Mitglieder mit Sollzeit --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Personen ({{ $mitglieder->count() }})</h2>
            <p class="mt-1 text-sm text-gray-500">Je Person die Sollarbeitszeit der Woche und die Kernarbeitszeit. Person anklicken zum Bearbeiten.</p>

            <div class="mt-3 divide-y divide-gray-100">
                @forelse ($mitglieder as $m)
                    @php $modell = $modelle[$m->id] ?? null; @endphp
                    <details class="group py-2" @if (session('offen') == $m->id) open @endif>
                        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 rounded-lg px-2 py-1.5 hover:bg-gray-50">
                            <span class="min-w-40 font-medium text-gray-800">
                                {{ $m->name }}
                                @if ($gruppe->leitung->contains('id', $m->id))<span class="ml-1 rounded bg-indigo-100 px-1.5 text-[11px] text-indigo-700">Leitung</span>@endif
                            </span>
                            @if ($modell)
                                <span class="text-sm text-gray-700">{{ $dauer($modell->wochenMinuten()) }} Std./Woche an {{ $modell->arbeitstageJeWoche() }} Tagen</span>
                                <span class="text-sm text-gray-500">{{ $modell->kernText() ? 'Kernzeit '.$modell->kernText() : 'keine Kernzeit' }}</span>
                                @if ($modell->gueltig_ab->isFuture())<span class="text-xs text-amber-700">gilt ab {{ $modell->gueltig_ab->format('d.m.Y') }}</span>@endif
                            @else
                                <span class="text-sm font-semibold text-red-600">noch keine Sollzeit</span>
                            @endif
                            <span class="ml-auto text-xs text-indigo-600 group-open:hidden">bearbeiten</span>
                        </summary>
                        <div class="mt-2 rounded-lg bg-gray-50 p-3">
                            @include('zeiterfassung::partials.zeitmodell-form', ['person' => $m, 'aktuell' => $modell, 'laender' => $laender, 'standardLand' => $standardLand])
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-2 text-xs">
                                <a href="{{ route('module.zeiterfassung.team.person', $m) }}" class="text-indigo-600 hover:underline">Zeiten, Urlaub und frühere Sollzeiten</a>
                                @unless ($mitgliederFest)
                                    <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.mitglied.entfernen', [$gruppe, $m]) }}"
                                          data-bestaetigen="{{ $m->name }} nimmt dann über diese Gruppe nicht mehr an der Zeiterfassung teil. Erfasste Zeiten bleiben erhalten."
                                          data-titel="Aus der Gruppe entfernen?" data-knopf="Entfernen">
                                        @csrf
                                        <button type="submit" class="rounded-lg px-2 py-1 text-red-600 hover:bg-red-50">aus der Gruppe entfernen</button>
                                    </form>
                                @endunless
                            </div>
                        </div>
                    </details>
                @empty
                    <p class="py-2 text-sm text-gray-400">Noch niemand in dieser Gruppe.</p>
                @endforelse
            </div>

            @if ($mitgliederFest)
                <p class="mt-3 text-xs text-gray-500">Die Mitglieder dieser Rolle pflegt der Abgleich „{{ $rolle->quelle }}“ – hier nur Sollzeit und Leitung.</p>
            @else
                <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.mitglied', $gruppe) }}" class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                    @csrf
                    <select name="user_id" required class="min-w-56 flex-1 rounded-lg border-gray-300 text-sm">
                        <option value="">Person hinzufügen …</option>
                        @foreach ($benutzer->whereNotIn('id', $mitglieder->pluck('id')) as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Hinzufügen</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
