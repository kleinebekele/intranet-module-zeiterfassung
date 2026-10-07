{{-- Felder für eine Abwesenheit; erwartet ein umgebendes x-data mit von, bis, art. --}}
<label class="block text-sm">
    <span class="text-gray-600">Art</span>
    <select name="art" x-model="art" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
        @foreach (\Intranet\Modules\Zeiterfassung\Models\Abwesenheit::ARTEN as $key => [$label])
            <option value="{{ $key }}">{{ $label }}</option>
        @endforeach
    </select>
</label>
<div class="grid grid-cols-2 gap-3">
    <label class="block text-sm">
        <span class="text-gray-600">Von</span>
        <input type="date" name="von" x-model="von" @change="if (! bis || bis < von) bis = von" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
    </label>
    <label class="block text-sm">
        <span class="text-gray-600">Bis</span>
        <input type="date" name="bis" x-model="bis" :min="von" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
    </label>
</div>
<label class="flex items-center gap-2 text-sm" x-show="von && von === bis">
    <input type="checkbox" name="halbtag" value="1" class="rounded border-gray-300 text-indigo-600">
    <span class="text-gray-600">nur halber Tag</span>
</label>
<label class="block text-sm">
    <span class="text-gray-600">Notiz (optional)</span>
    <input type="text" name="notiz" maxlength="500" value="{{ old('notiz') }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
</label>
