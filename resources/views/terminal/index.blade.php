<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Zeiterfassung · {{ $terminal->name }}</title>
    @includeIf('layouts.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        html, body { overscroll-behavior: none; }
        body { -webkit-tap-highlight-color: transparent; user-select: none; }
    </style>
</head>
<body class="h-full bg-gray-100 font-sans antialiased text-gray-800">

<div x-data="zeitTerminal" class="flex min-h-full flex-col">
    <header class="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-3">
        <div>
            <div class="text-lg font-semibold">Zeiterfassung</div>
            <div class="text-sm text-gray-500">{{ $terminal->name }}</div>
        </div>
        <div class="text-right">
            <div class="font-mono text-4xl font-bold tabular-nums" x-text="uhr"></div>
            <div class="text-sm text-gray-500" x-text="datum"></div>
        </div>
    </header>

    {{-- Ergebnis / Meldung --}}
    <div x-show="meldung" x-cloak class="px-6 py-4 text-center text-xl font-semibold"
         :class="meldungOk ? 'bg-green-600 text-white' : 'bg-red-600 text-white'" x-text="meldung"></div>

    <main class="flex flex-1 flex-col items-center justify-center gap-8 p-6 lg:flex-row lg:items-stretch lg:gap-12">

        {{-- Erkannte Person: Aktionen --}}
        <template x-if="person">
            <div class="flex w-full max-w-2xl flex-col items-center justify-center gap-6 rounded-2xl bg-white p-8 shadow">
                <div class="text-center">
                    <div class="text-3xl font-bold" x-text="person.name"></div>
                    <div class="mt-2 text-lg text-gray-500">
                        <span x-text="{ da: 'Anwesend', pause: 'In der Pause', weg: 'Nicht eingestempelt' }[person.zustand]"></span>
                        <span x-show="person.seit" x-text="'seit ' + person.seit"></span>
                        · heute <span x-text="person.heute"></span> Std.
                    </div>
                    <div x-show="person.warnung" class="mt-3 rounded-lg bg-red-50 px-4 py-2 text-base font-medium text-red-700" x-text="person.warnung"></div>
                </div>
                <div class="grid w-full gap-4" :class="person.aktionen.length > 1 ? 'grid-cols-2' : 'grid-cols-1'">
                    <template x-for="a in person.aktionen" :key="a.key">
                        <button type="button" @click="buchen(a.key)" :disabled="busy"
                                class="rounded-2xl py-8 text-3xl font-bold text-white shadow-lg active:scale-95 disabled:opacity-50"
                                :class="{ kommen: 'bg-green-600', weiter: 'bg-green-600', pause: 'bg-amber-500', gehen: 'bg-gray-700' }[a.key]"
                                x-text="a.label"></button>
                    </template>
                </div>
                <button type="button" @click="zuruecksetzen()" class="text-base text-gray-500 underline">Abbrechen (<span x-text="rest"></span> s)</button>
            </div>
        </template>

        {{-- Anmeldung: Chip oder Code --}}
        <template x-if="! person">
            <div class="flex w-full flex-col items-center gap-8 lg:flex-row lg:items-stretch lg:justify-center">
                <div class="flex w-full max-w-md flex-col items-center justify-center gap-4 rounded-2xl bg-indigo-600 p-8 text-white shadow" :class="busy ? 'animate-pulse' : ''">
                    <svg class="h-24 w-24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z"/></svg>
                    <div class="text-3xl font-semibold">Chip auflegen</div>
                    <div class="text-indigo-200">oder Code eingeben</div>
                    <button type="button" x-show="nfcMoeglich && ! nfcAktiv" @click="nfcStarten()"
                            class="mt-2 rounded-xl bg-white px-5 py-3 text-lg font-semibold text-indigo-700">NFC einschalten</button>
                    <div x-show="nfcAktiv" class="text-sm text-indigo-200">NFC bereit</div>
                </div>

                <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow">
                    <div class="mb-4 flex h-16 items-center justify-center rounded-xl bg-gray-100 font-mono text-4xl tracking-[0.4em]"
                         x-text="code.replace(/./g, '•') || ' '"></div>
                    <div class="grid grid-cols-3 gap-3">
                        <template x-for="z in ['1','2','3','4','5','6','7','8','9']" :key="z">
                            <button type="button" @click="taste(z)" class="rounded-xl bg-gray-100 py-5 text-3xl font-semibold active:bg-gray-300" x-text="z"></button>
                        </template>
                        <button type="button" @click="code = ''" class="rounded-xl bg-gray-100 py-5 text-xl text-gray-500 active:bg-gray-300">C</button>
                        <button type="button" @click="taste('0')" class="rounded-xl bg-gray-100 py-5 text-3xl font-semibold active:bg-gray-300">0</button>
                        <button type="button" @click="codeSenden()" :disabled="code.length < 4"
                                class="rounded-xl bg-indigo-600 py-5 text-xl font-semibold text-white active:bg-indigo-800 disabled:opacity-40">OK</button>
                    </div>
                </div>
            </div>
        </template>
    </main>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('zeitTerminal', () => ({
            urlErkennen: @js($urlErkennen),
            urlBuchen: @js($urlBuchen),
            csrf: document.querySelector('meta[name=csrf-token]').content,
            uhr: '', datum: '',
            person: null,
            code: '',
            busy: false,
            meldung: '', meldungOk: true, meldungTimer: null,
            rest: 0, restTimer: null,
            nfcMoeglich: 'NDEFReader' in window, nfcAktiv: false,
            wedgeBuf: '', wedgeAt: 0,

            init() {
                this.tick();
                setInterval(() => this.tick(), 1000);
                window.addEventListener('keydown', (e) => this.onKey(e));
                // Einmal freigegeben, startet NFC ohne Fingertipp (Chrome merkt sich die Erlaubnis).
                if (this.nfcMoeglich && navigator.permissions) {
                    navigator.permissions.query({ name: 'nfc' }).then(p => { if (p.state === 'granted') this.nfcStarten(); }).catch(() => {});
                }
                // Seite alle 6 Std. neu laden: frisches CSRF-Token, neue Programmversion.
                setTimeout(() => { if (! this.person) location.reload(); }, 6 * 3600 * 1000);
            },

            tick() {
                const d = new Date();
                this.uhr = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
                this.datum = d.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });
            },

            taste(z) { if (this.code.length < 10) this.code += z; },
            codeSenden() { if (this.code.length >= 4) { const c = this.code; this.code = ''; this.erkennen(c); } },

            async post(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });
                if (res.status === 419) { location.reload(); throw new Error('Sitzung abgelaufen'); }
                if (res.status === 429) throw new Error('Zu viele Versuche – bitte kurz warten.');
                return res.json();
            },

            async erkennen(eingabe) {
                if (this.busy) return;
                this.busy = true;
                try {
                    const data = await this.post(this.urlErkennen, { eingabe });
                    if (data.found) {
                        this.person = data;
                        this.zeige('', true);
                        this.countdown(15);
                    } else {
                        this.zeige(data.meldung + (data.kennung ? ' (Kennung ' + data.kennung + ')' : ''), false);
                    }
                } catch (e) {
                    this.zeige(e.message || 'Keine Verbindung – bitte noch einmal.', false);
                }
                this.busy = false;
            },

            async buchen(aktion) {
                if (this.busy) return;
                this.busy = true;
                try {
                    const data = await this.post(this.urlBuchen, { aktion });
                    this.zeige(data.ok ? data.text : data.meldung, !! data.ok);
                } catch (e) {
                    this.zeige(e.message || 'Keine Verbindung – bitte noch einmal.', false);
                }
                this.busy = false;
                this.zuruecksetzen(false);
            },

            countdown(sek) {
                clearInterval(this.restTimer);
                this.rest = sek;
                this.restTimer = setInterval(() => { if (--this.rest <= 0) this.zuruecksetzen(); }, 1000);
            },

            zuruecksetzen(meldungWeg = true) {
                clearInterval(this.restTimer);
                this.person = null;
                this.code = '';
                if (meldungWeg) this.meldung = '';
            },

            zeige(text, ok) {
                clearTimeout(this.meldungTimer);
                this.meldung = text;
                this.meldungOk = ok;
                if (text) this.meldungTimer = setTimeout(() => { this.meldung = ''; }, 6000);
            },

            async nfcStarten() {
                try {
                    const reader = new NDEFReader();
                    await reader.scan();
                    this.nfcAktiv = true;
                    reader.onreading = (e) => { if (! this.person) this.erkennen(e.serialNumber || ''); };
                    reader.onreadingerror = () => this.zeige('Chip nicht lesbar – bitte noch einmal.', false);
                } catch (err) {
                    this.nfcAktiv = false;
                    this.zeige('NFC nicht verfügbar: ' + (err && err.message ? err.message : err), false);
                }
            },

            // Ziffern über die Tastatur; USB-Leser in Tastatur-Emulation tippen schnell + Enter.
            onKey(e) {
                if (e.ctrlKey || e.altKey || e.metaKey) return;
                const jetzt = Date.now();
                if (e.key === 'Enter' || e.key === 'Tab') {
                    e.preventDefault();
                    if (this.wedgeBuf.length >= 4 && jetzt - this.wedgeAt < 300) {
                        const k = this.wedgeBuf; this.wedgeBuf = ''; this.code = '';
                        if (! this.person) this.erkennen(k);
                    } else if (! this.person) {
                        this.codeSenden();
                    }
                    this.wedgeBuf = '';
                    return;
                }
                if (e.key === 'Backspace') { this.code = this.code.slice(0, -1); return; }
                if (e.key.length !== 1) return;
                if (jetzt - this.wedgeAt > 300) this.wedgeBuf = '';
                this.wedgeAt = jetzt;
                this.wedgeBuf += e.key;
                if (/\d/.test(e.key) && ! this.person) this.taste(e.key);
            },
        }));
    });
</script>
</body>
</html>
