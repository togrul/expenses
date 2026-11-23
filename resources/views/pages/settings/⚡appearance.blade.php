<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Görünüş')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Görünüş') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Görünüş')" :subheading="__('“Sistem” iPhone-un açıq/tünd rejimini avtomatik izləyir.')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Açıq') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Tünd') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('Sistem') }}</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
