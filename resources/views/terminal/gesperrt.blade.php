<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Zeiterfassung</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-6 font-sans text-gray-800">
    <div class="max-w-lg rounded-2xl bg-white p-8 text-center shadow">
        <div class="text-2xl font-semibold">Zeiterfassung</div>
        <p class="mt-3 text-gray-600">{{ $grund }}</p>
        <p class="mt-2 text-sm text-gray-400">Die Adresse des Terminals vergibt die Verwaltung unter Zeiterfassung → Einstellungen.</p>
    </div>
</body>
</html>
