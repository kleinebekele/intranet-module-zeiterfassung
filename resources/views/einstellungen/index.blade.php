<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Zeiterfassung – Einstellungen</h1>
    </x-slot>

    @include('zeiterfassung::partials.meldung')

    @if (session('zeit_terminal_url'))
        <div class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
            <div class="font-semibold">Adresse für das Terminal</div>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <code class="break-all rounded bg-white px-2 py-1 text-xs">{{ session('zeit_terminal_url') }}</code>
                <a href="{{ session('zeit_terminal_url') }}" target="_blank" class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700">öffnen</a>
            </div>
            <div class="mt-1 text-xs text-indigo-700">
                Wird nur jetzt angezeigt. Am Tablet als Startseite im Kiosk-Modus öffnen. Geht die Adresse verloren: „Neuer Schlüssel".
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- Gruppen --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Gruppen & Leitung</h2>
            <p class="mt-1 text-sm text-gray-500">
                Wer eine dieser Rollen hat, nimmt an der Zeiterfassung teil. Die Leitung sieht und korrigiert die Zeiten der Mitglieder
                und gibt Anträge frei. Die Rolle „Zeiterfassung: Verwaltung" sieht alle.
            </p>
            <div class="mt-4 divide-y divide-gray-100">
                @forelse ($gruppen as $g)
                    <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.leitung', $g) }}"
                          class="flex flex-wrap items-start gap-3 py-3">
                        @csrf
                        <div class="w-56">
                            <div class="font-medium text-gray-800">{{ $g->name() }}</div>
                            <div class="text-xs text-gray-400">{{ $mitglieder[$g->role_id] ?? 0 }} Mitglieder</div>
                        </div>
                        <select name="leitung[]" multiple size="4" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm">
                            @foreach ($benutzer as $b)
                                <option value="{{ $b->id }}" @selected($g->leitung->contains('id', $b->id))>{{ $b->name }}</option>
                            @endforeach
                        </select>
                        <div class="flex flex-col gap-2">
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Leitung speichern</button>
                            <button type="submit" formaction="{{ route('module.zeiterfassung.einstellungen.gruppe.entfernen', $g) }}"
                                    onclick="return confirm('Gruppe {{ $g->name() }} aus der Zeiterfassung entfernen? Erfasste Zeiten bleiben erhalten.')"
                                    class="rounded-lg px-3 py-1.5 text-sm text-red-600 hover:bg-red-50">Gruppe entfernen</button>
                        </div>
                    </form>
                @empty
                    <p class="py-3 text-sm text-gray-400">Noch keine Gruppe – ohne Gruppe nimmt niemand teil.</p>
                @endforelse
            </div>
            <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.gruppe.anlegen') }}" class="mt-3 flex flex-wrap gap-2 border-t border-gray-100 pt-3">
                @csrf
                <select name="role_id" required class="min-w-64 rounded-lg border-gray-300 text-sm">
                    <option value="">Rolle als Gruppe hinzufügen …</option>
                    @foreach ($rollen as $r)
                        <option value="{{ $r->role_id }}">{{ $r->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Hinzufügen</button>
            </form>
            <p class="mt-2 text-xs text-gray-400">Mehrfachauswahl in der Leitung: Strg (bzw. ⌘) gedrückt halten.</p>
        </div>

        {{-- Regeln --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Regeln (Arbeitszeitgesetz)</h2>
            <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.regeln') }}" class="mt-3 space-y-3">
                @csrf
                <label class="block text-sm">
                    <span class="text-gray-600">Feiertage nach Bundesland (Standard; je Person abweichend möglich)</span>
                    <select name="bundesland" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        @foreach ($laender as $code => $name)
                            <option value="{{ $code }}" @selected($werte['bundesland'] === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="text-gray-600">Höchstens je Tag (§ 3)</span>
                        <input type="text" name="max_tag" value="{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $werte['max_tag']) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                    <label class="block text-sm"><span class="text-gray-600">Am Stück ohne Pause (§ 4)</span>
                        <input type="text" name="max_block" value="{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $werte['max_block']) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                    <label class="block text-sm"><span class="text-gray-600">Ruhezeit (§ 5)</span>
                        <input type="text" name="ruhezeit" value="{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $werte['ruhezeit']) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                    <label class="block text-sm"><span class="text-gray-600">Höchstens je Woche</span>
                        <input type="text" name="max_woche" value="{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer((int) $werte['max_woche']) }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="pause_abziehen" value="1" @checked($werte['pause_abziehen'] === '1') class="mt-0.5 rounded border-gray-300 text-indigo-600">
                    <span class="text-gray-600">Fehlende gesetzliche Pause automatisch abziehen (über 6 Std. 30 Min., über 9 Std. 45 Min.; höchstens bis auf die Schwelle)</span>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="sonntag_warnen" value="1" @checked($werte['sonntag_warnen'] === '1') class="rounded border-gray-300 text-indigo-600">
                    <span class="text-gray-600">Hinweis bei Arbeit an Sonn- und Feiertagen (§ 9)</span>
                </label>
                <label class="block text-sm">
                    <span class="text-gray-600">Länge zufälliger Terminal-Codes (Ziffern)</span>
                    <input type="number" name="code_laenge" min="4" max="10" value="{{ $werte['code_laenge'] }}" class="mt-1 w-32 rounded-lg border-gray-300 text-sm">
                </label>
                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Regeln speichern</button>
                </div>
            </form>
        </div>

        {{-- Terminals --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Terminals</h2>
            <p class="mt-1 text-sm text-gray-500">
                Ein Terminal ist ein Tablet im Kiosk-Modus. Chips liest ein Android-Tablet per NFC (Chrome); ein USB-Leser in
                Tastatur-Emulation geht an jedem Gerät. Ohne Chip meldet man sich mit dem persönlichen Code an.
            </p>
            <div class="mt-3 divide-y divide-gray-100">
                @foreach ($terminals as $t)
                    <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.terminal.speichern', $t) }}" class="space-y-2 py-3">
                        @csrf
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" name="name" value="{{ $t->name }}" required class="min-w-40 flex-1 rounded-lg border-gray-300 text-sm">
                            <label class="flex items-center gap-1 text-sm text-gray-600">
                                <input type="checkbox" name="aktiv" value="1" @checked($t->aktiv) class="rounded border-gray-300 text-indigo-600"> aktiv
                            </label>
                            <span class="text-xs text-gray-400">{{ $t->zuletzt_am ? 'zuletzt '.$t->zuletzt_am->diffForHumans() : 'noch nie benutzt' }}</span>
                        </div>
                        <textarea name="netze" rows="2" placeholder="Nur aus diesen Netzen (leer = überall), z. B. 192.168.10.0/24"
                                  class="w-full rounded-lg border-gray-300 font-mono text-xs">{{ $t->netze }}</textarea>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="submit" formaction="{{ route('module.zeiterfassung.einstellungen.terminal.entfernen', $t) }}"
                                    onclick="return confirm('Terminal {{ $t->name }} entfernen?')" class="rounded-lg px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">Entfernen</button>
                            <button type="submit" formaction="{{ route('module.zeiterfassung.einstellungen.terminal.schluessel', $t) }}"
                                    onclick="return confirm('Neuer Schlüssel: Die bisherige Adresse funktioniert dann nicht mehr. Fortfahren?')"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">Neuer Schlüssel</button>
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">Speichern</button>
                        </div>
                    </form>
                @endforeach
            </div>
            <form method="POST" action="{{ route('module.zeiterfassung.einstellungen.terminal.anlegen') }}" class="mt-3 space-y-2 border-t border-gray-100 pt-3">
                @csrf
                <input type="text" name="name" required placeholder="Name, z. B. Lehrerzimmer oder Filiale Nord" class="w-full rounded-lg border-gray-300 text-sm">
                <textarea name="netze" rows="2" placeholder="Nur aus diesen Netzen (optional)" class="w-full rounded-lg border-gray-300 font-mono text-xs"></textarea>
                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Terminal anlegen</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
