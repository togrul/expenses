<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Qarşıdakı ödənişlər')] class extends Component {
    //
}; ?>

<div class="max-w-3xl">
    <x-page-header :eyebrow="__('Təqvim')" :heading="__('Qarşıdakı ödənişlər')" :subheading="__('Borc hissələri və daimi ödənişlər bir siyahıda')" />

    <div class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-faint">
        <span class="flex items-center gap-2"><span class="flex size-4 items-center justify-center rounded-full border-[1.5px] border-line-strong"></span>{{ __('ödənildi kimi qeyd et') }}</span>
        <span class="flex items-center gap-2"><span class="size-4 rounded-md border border-line-strong"></span>{{ __('cəmləmək üçün seç') }}</span>
    </div>

    <livewire:upcoming-list searchable />
</div>
