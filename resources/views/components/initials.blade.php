{{-- Monogram avatar with a stable, name-derived tint. --}}
@props(['name', 'size' => 'md'])

@php
    $hue = crc32(mb_strtolower($name)) % 360;
    $letters = collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
    $sizeClass = $size === 'lg' ? 'size-12 text-base rounded-2xl' : 'size-10 text-sm rounded-xl';
@endphp

<span
    {{ $attributes->class(['flex shrink-0 items-center justify-center font-semibold', $sizeClass]) }}
    style="background: linear-gradient(135deg, hsl({{ $hue }} 45% 55% / 0.22), hsl({{ ($hue + 40) % 360 }} 45% 45% / 0.12)); color: hsl({{ $hue }} 55% 68%); box-shadow: inset 0 0 0 1px hsl({{ $hue }} 45% 60% / 0.25);"
    aria-hidden="true"
>{{ $letters ?: '?' }}</span>
