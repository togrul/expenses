<?php

use App\Support\Preferences;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Maliyyə ayarları')] class extends Component {
    public string $currency = '';

    public string $dateFormat = '';

    public int $weekStartsOn = 1;

    public string $locale = '';

    public string $exportFrom = '';

    public string $exportTo = '';

    public function mount(): void
    {
        $this->currency = Preferences::currency();
        $this->dateFormat = Preferences::dateFormat();
        $this->weekStartsOn = Preferences::weekStartsOn();
        $this->locale = (string) Preferences::get('locale');
        $this->exportFrom = today()->startOfYear()->toDateString();
        $this->exportTo = today()->toDateString();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'currency' => ['required', Rule::in(array_keys(config('finance.currencies')))],
            'dateFormat' => ['required', Rule::in(config('finance.date_formats'))],
            'weekStartsOn' => ['required', 'integer', Rule::in([1, 7])],
            'locale' => ['required', Rule::in(array_keys(config('finance.locales')))],
        ]);

        $user = Auth::user();
        $user->preferences = [
            ...($user->preferences ?? []),
            'currency' => $validated['currency'],
            'date_format' => $validated['dateFormat'],
            'week_starts_on' => $validated['weekStartsOn'],
            'locale' => $validated['locale'],
        ];
        $user->save();

        Flux::toast(variant: 'success', text: __('Ayarlar yadda saxlanıldı.'));

        $this->redirectRoute('finance.edit', navigate: true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Maliyyə')" :subheading="__('Valyuta, tarix formatı və məlumatların ixracı')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:select wire:model="currency" :label="__('Valyuta')" :description="__('Yeni qeydlər bu valyuta ilə saxlanılır.')">
                @foreach (config('finance.currencies') as $code => $symbol)
                    <flux:select.option :value="$code">{{ $code }} ({{ $symbol }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="dateFormat" :label="__('Tarix formatı')">
                @foreach (config('finance.date_formats') as $format)
                    <flux:select.option :value="$format">{{ $format }} — {{ today()->format($format) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="weekStartsOn" :label="__('Həftənin ilk günü')">
                <flux:select.option value="1">{{ __('Bazar ertəsi') }}</flux:select.option>
                <flux:select.option value="7">{{ __('Bazar') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="locale" :label="__('Dil')">
                @foreach (config('finance.locales') as $code => $label)
                    <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button variant="primary" type="submit">{{ __('Yadda saxla') }}</flux:button>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <flux:heading>{{ __('Export') }}</flux:heading>
        <flux:subheading>{{ __('Əməliyyatları CSV faylı kimi yüklə (Excel-də açılır).') }}</flux:subheading>

        <form method="GET" action="{{ route('transactions.export') }}" class="mt-4 flex flex-wrap items-end gap-3">
            <flux:input type="date" name="from" wire:model="exportFrom" :label="__('Başlanğıc')" class="w-44" />
            <flux:input type="date" name="to" wire:model="exportTo" :label="__('Son')" class="w-44" />
            <flux:button type="submit" icon="arrow-down-tray">{{ __('CSV yüklə') }}</flux:button>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <flux:heading>{{ __('Backup') }}</flux:heading>
        <flux:text class="mt-2 text-sm">
            {{ __('Baza hər gün avtomatik olaraq storage/backups qovluğuna yedəklənir (scheduler işləyirsə). Əl ilə:') }}
        </flux:text>
        <pre class="mt-2 rounded-lg bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-800"><code>php artisan finance:backup</code></pre>
    </x-pages::settings.layout>
</section>
