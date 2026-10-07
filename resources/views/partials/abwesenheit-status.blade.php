@php
    $klasse = [
        'beantragt' => 'bg-amber-100 text-amber-800',
        'genehmigt' => 'bg-green-100 text-green-800',
        'abgelehnt' => 'bg-red-100 text-red-700',
        'storniert' => 'bg-gray-100 text-gray-500',
    ][$a->status] ?? 'bg-gray-100 text-gray-600';
@endphp
<span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $klasse }}">{{ $a->statusText() }}</span>
