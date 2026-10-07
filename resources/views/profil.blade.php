@php
    $hatCode = \Intranet\Modules\Zeiterfassung\Models\Ausweis::where('user_id', $user->id)->where('art', 'code')->exists();
    $chips = \Intranet\Modules\Zeiterfassung\Models\Ausweis::where('user_id', $user->id)->where('art', 'chip')->count();
@endphp
<h2 class="text-lg font-medium text-gray-900">Zeiterfassung</h2>
<p class="mt-1 text-sm text-gray-600">
    Am Stempel-Terminal meldest du dich mit deinem Chip oder deinem persönlichen Code an.
    {{ $chips ? "Dir sind {$chips} Chip(s) zugeordnet." : '' }}
</p>
@if (session('zeit_code'))
    <div class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
        Dein neuer Code: <span class="font-mono text-2xl font-bold tracking-widest">{{ session('zeit_code') }}</span>
        <div class="text-xs text-indigo-700">Er wird nur jetzt angezeigt.</div>
    </div>
@endif
<form method="POST" action="{{ route('module.zeiterfassung.meine.code') }}" class="mt-3"
      data-bestaetigen="{{ $hatCode ? 'Neuen Code erzeugen? Der alte gilt dann nicht mehr.' : 'Code erzeugen?' }}">
    @csrf
    <span class="mr-3 text-sm text-gray-700">{{ $hatCode ? 'Code ist gesetzt.' : 'Noch kein Code.' }}</span>
    <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
        {{ $hatCode ? 'Neuen Code erzeugen' : 'Code erzeugen' }}
    </button>
</form>
