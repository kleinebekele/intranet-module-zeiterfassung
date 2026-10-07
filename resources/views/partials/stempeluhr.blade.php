{{-- Statuskarte mit Kommen/Pause/Gehen. Erwartet: $status, $route (Formularziel), $darfStempeln --}}
@php
    $farbe = ['da' => 'bg-green-500', 'pause' => 'bg-amber-400', 'weg' => 'bg-gray-300'][$status['zustand']];
    $text = ['da' => 'Anwesend', 'pause' => 'In der Pause', 'weg' => 'Nicht eingestempelt'][$status['zustand']];
    $maxBlock = \Intranet\Modules\Zeiterfassung\Support\Einstellungen::int('max_block');
@endphp
<div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-5">
    <div class="flex items-center gap-4">
        <span class="relative flex h-4 w-4">
            @if ($status['zustand'] === 'da')
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-60"></span>
            @endif
            <span class="relative inline-flex h-4 w-4 rounded-full {{ $farbe }}"></span>
        </span>
        <div>
            <div class="text-lg font-semibold text-gray-800">{{ $text }}
                @if ($status['seit'])
                    <span class="text-sm font-normal text-gray-500">seit {{ $status['seit']->isToday() ? $status['seit']->format('H:i') : $status['seit']->format('d.m. H:i') }}</span>
                @endif
            </div>
            <div class="text-sm text-gray-500">Heute gearbeitet: <strong class="text-gray-800">{{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($status['heute']) }} Std.</strong></div>
            @if ($status['zustand'] === 'da' && $status['block'] >= $maxBlock - 15)
                <div class="mt-1 text-sm font-medium text-red-700">⚠ Seit {{ \Intranet\Modules\Zeiterfassung\Support\Format::dauer($status['block']) }} Std. ohne Pause – bitte Pause machen (§ 4 ArbZG).</div>
            @endif
        </div>
    </div>

    @if ($darfStempeln)
        <form method="POST" action="{{ $route }}" class="flex flex-wrap gap-2">
            @csrf
            @foreach ($status['aktionen'] as $aktion)
                <button type="submit" name="aktion" value="{{ $aktion }}"
                        class="rounded-xl px-6 py-3 text-base font-semibold text-white shadow
                               {{ match ($aktion) { 'kommen', 'weiter' => 'bg-green-600 hover:bg-green-700', 'pause' => 'bg-amber-500 hover:bg-amber-600', default => 'bg-gray-700 hover:bg-gray-800' } }}">
                    {{ \Intranet\Modules\Zeiterfassung\Support\Stempeluhr::AKTIONEN[$aktion] }}
                </button>
            @endforeach
        </form>
    @endif
</div>
