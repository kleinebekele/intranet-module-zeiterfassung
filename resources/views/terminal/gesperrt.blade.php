<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    {{-- Alle 5 Minuten neu versuchen: sind die Netze eingetragen, startet das Terminal von selbst. --}}
    <meta http-equiv="refresh" content="300">
    <title>Zeiterfassung · {{ $terminal->name }}</title>
    @includeIf('layouts.favicon')
    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full items-center justify-center bg-gray-100 p-6 font-sans antialiased text-gray-800">
    <div class="w-full max-w-xl rounded-2xl bg-white p-8 text-center shadow">
        <div class="text-lg font-semibold">Zeiterfassung · {{ $terminal->name }}</div>
        <div class="mt-4 rounded-xl bg-red-600 px-6 py-4 text-xl font-semibold text-white">{{ $meldung }}</div>
    </div>
</body>
</html>
