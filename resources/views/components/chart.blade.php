@props(['options', 'money' => true, 'height' => 280])

<div
    wire:ignore
    wire:key="chart-{{ md5(json_encode($options)) }}"
    x-data="apexChart(@js(array_replace_recursive(['chart' => ['height' => $height]], $options)), @js($money ? \App\Support\Money::symbol() : null))"
    {{ $attributes }}
>
    <div x-ref="canvas"></div>
</div>
