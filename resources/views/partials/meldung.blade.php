@if (session('error'))
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
@endif
@if (session('zeit_code'))
    <div class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
        Neuer Terminal-Code: <span class="ml-1 font-mono text-2xl font-bold tracking-widest">{{ session('zeit_code') }}</span>
        <div class="mt-1 text-xs text-indigo-700">Der Code wird nur jetzt angezeigt – bitte merken bzw. weitergeben. Der alte Code gilt nicht mehr.</div>
    </div>
@endif
