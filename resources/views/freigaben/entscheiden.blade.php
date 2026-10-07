<form method="POST" action="{{ $ziel }}" class="flex flex-wrap items-center gap-2">
    @csrf
    <input type="text" name="notiz" maxlength="500" placeholder="Anmerkung (optional)" class="w-40 rounded-lg border-gray-300 text-xs">
    <button type="submit" name="entscheid" value="ja" class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700">Freigeben</button>
    <button type="submit" name="entscheid" value="nein" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">Ablehnen</button>
</form>
