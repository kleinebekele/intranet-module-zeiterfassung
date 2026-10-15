{{-- Statusvorlage anlegen/ändern; erwartet $v (Statusvorlage|null). --}}
<form method="POST" action="{{ route('module.zeiterfassung.meine.vorlage') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-6 xl:items-end">
    @csrf
    @if ($v)<input type="hidden" name="id" value="{{ $v->id }}">@endif
    <label class="block text-xs xl:col-span-1"><span class="text-gray-500">Name</span>
        <input type="text" name="name" required maxlength="100" value="{{ $v?->name }}" placeholder="z. B. Mittagspause" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
    <label class="block text-xs"><span class="text-gray-500">Dauer</span>
        <select name="minuten" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            @for ($m = 15; $m <= 300; $m += 15)
                <option value="{{ $m }}" @selected(($v?->minuten ?? 30) === $m)>{{ intdiv($m, 60) ? intdiv($m, 60).' Std.'.($m % 60 ? ' '.($m % 60).' Min.' : '') : $m.' Min.' }}</option>
            @endfor
        </select></label>
    <label class="block text-xs"><span class="text-gray-500">Teams-Status</span>
        <select name="verfuegbarkeit" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            @foreach (\Intranet\Modules\Zeiterfassung\Models\Statusvorlage::STATUS as $schluessel => [$bezeichnung])
                <option value="{{ $schluessel }}" @selected(($v?->verfuegbarkeit ?? 'BeRightBack') === $schluessel)>{{ $bezeichnung }}</option>
            @endforeach
        </select></label>
    <label class="block text-xs xl:col-span-2"><span class="text-gray-500">Statustext in Teams (optional)</span>
        <input type="text" name="text" maxlength="280" value="{{ $v?->text }}" placeholder="z. B. Mittagspause – ab 13 Uhr wieder erreichbar" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
    <label class="block text-xs"><span class="text-gray-500">Danach zurück auf online</span>
        <select name="rueckkehr" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            @foreach (\Intranet\Modules\Zeiterfassung\Models\Statusvorlage::RUECKKEHR as $schluessel => $bezeichnung)
                <option value="{{ $schluessel }}" @selected(($v?->rueckkehr ?? 'frage') === $schluessel)>{{ $bezeichnung }}</option>
            @endforeach
        </select></label>
    <div class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 xl:col-span-6">
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="pause" value="1" @checked($v?->pause) class="rounded border-gray-300 text-indigo-600">
            zugleich Pause buchen (endet nie von selbst – das Programm fragt nach der Dauer)
        </label>
        <button type="submit" class="rounded-lg {{ $v ? 'border border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-indigo-600 font-medium text-white hover:bg-indigo-700' }} px-3 py-1.5 text-sm">{{ $v ? 'Speichern' : 'Vorlage anlegen' }}</button>
    </div>
</form>
