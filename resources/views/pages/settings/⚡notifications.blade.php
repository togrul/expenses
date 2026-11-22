<?php

use App\Notifications\PushMessage;
use App\Support\Preferences;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bildirişlər')] class extends Component {
    public bool $notifyToday = true;

    public bool $notifyTomorrow = true;

    public bool $notifyOverdue = true;

    public bool $notifyMonthly = true;

    public int $notifyHour = 9;

    public function mount(): void
    {
        $this->notifyToday = (bool) Preferences::get('notify_today');
        $this->notifyTomorrow = (bool) Preferences::get('notify_tomorrow');
        $this->notifyOverdue = (bool) Preferences::get('notify_overdue');
        $this->notifyMonthly = (bool) Preferences::get('notify_monthly');
        $this->notifyHour = (int) Preferences::get('notify_hour');
    }

    #[Computed]
    public function deviceCount(): int
    {
        return Auth::user()->pushSubscriptions()->count();
    }

    #[On('push-subscription-changed')]
    public function subscriptionChanged(): void
    {
        unset($this->deviceCount);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'notifyToday' => ['boolean'],
            'notifyTomorrow' => ['boolean'],
            'notifyOverdue' => ['boolean'],
            'notifyMonthly' => ['boolean'],
            'notifyHour' => ['required', 'integer', Rule::in(config('finance.notify_hours'))],
        ]);

        $user = Auth::user();
        $user->preferences = [
            ...($user->preferences ?? []),
            'notify_today' => $validated['notifyToday'],
            'notify_tomorrow' => $validated['notifyTomorrow'],
            'notify_overdue' => $validated['notifyOverdue'],
            'notify_monthly' => $validated['notifyMonthly'],
            'notify_hour' => $validated['notifyHour'],
        ];
        $user->save();

        Flux::toast(variant: 'success', text: __('Bildiriş ayarları yadda saxlanıldı.'));
    }

    public function sendTest(): void
    {
        if ($this->deviceCount === 0) {
            Flux::toast(variant: 'danger', text: __('Əvvəlcə bu cihazda bildirişləri aktiv edin.'));

            return;
        }

        Auth::user()->notifyNow(new PushMessage(
            title: __('Bildirişlər işləyir'),
            body: __('Ödəniş xatırlatmaları bu cihaza gələcək.'),
            url: route('upcoming.index', absolute: false),
            tag: 'test',
        ));

        Flux::toast(variant: 'success', text: __('Test bildirişi göndərildi.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Bildirişlər')" :subheading="__('Ödəniş xatırlatmaları telefona push bildiriş kimi gəlir')">
        <div
            x-data="pushSubscription(@js(config('webpush.vapid.public_key')))"
            class="card my-6 space-y-4 p-5"
        >
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gold-soft text-gold">
                    <flux:icon name="bell" variant="outline" class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-semibold text-ink">{{ __('Bu cihaz') }}</div>
                    <div class="text-sm text-muted">
                        <span x-show="state === 'on'">{{ __('Bildirişlər aktivdir.') }}</span>
                        <span x-show="state === 'off'" x-cloak>{{ __('Bildirişlər söndürülüb.') }}</span>
                        <span x-show="state === 'denied'" x-cloak>{{ __('İcazə rədd edilib. iPhone Ayarlar → Bildirişlər → Maliyyə bölməsindən açın.') }}</span>
                        <span x-show="state === 'install'" x-cloak>{{ __('iPhone-da bildirişlər üçün əvvəlcə Safari-də Paylaş → "Ana ekrana əlavə et" edin və tətbiqi ikondan açın.') }}</span>
                        <span x-show="state === 'unsupported'" x-cloak>{{ __('Bu brauzer push bildirişlərini dəstəkləmir.') }}</span>
                        <span x-show="state === 'loading'" x-cloak>{{ __('Yoxlanılır…') }}</span>
                    </div>
                    <div class="mt-1 text-xs text-faint">{{ trans_choice('{0} Hələ heç bir cihaz qoşulmayıb.|{1} Hesaba 1 cihaz qoşulub.|[2,*] Hesaba :count cihaz qoşulub.', $this->deviceCount, ['count' => $this->deviceCount]) }}</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" icon="bell" x-show="state === 'off'" x-cloak x-on:click="enable" x-bind:disabled="busy" data-test="enable-push-button">
                    {{ __('Bildirişləri aç') }}
                </flux:button>
                <flux:button variant="ghost" icon="bell-slash" x-show="state === 'on'" x-cloak x-on:click="disable" x-bind:disabled="busy">
                    {{ __('Bu cihazda söndür') }}
                </flux:button>
                <flux:button icon="paper-airplane" wire:click="sendTest" x-show="state === 'on'" x-cloak>
                    {{ __('Test bildirişi göndər') }}
                </flux:button>
            </div>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-negative" role="alert"></p>
        </div>

        <form wire:submit="save" class="space-y-6">
            <flux:fieldset>
                <flux:legend>{{ __('Nə barədə xəbər verək?') }}</flux:legend>

                <div class="space-y-4">
                    <flux:switch wire:model="notifyToday" :label="__('Bu gün ödənilməli olanlar')" :description="__('Ödəniş günü səhər xatırlatma.')" />
                    <flux:switch wire:model="notifyTomorrow" :label="__('Sabahkı ödənişlər')" :description="__('Ödənişdən 1 gün əvvəl.')" />
                    <flux:switch wire:model="notifyOverdue" :label="__('Gecikmiş ödənişlər')" :description="__('Ödənilməyənə qədər hər gün.')" />
                    <flux:switch wire:model="notifyMonthly" :label="__('Ay yekunu')" :description="__('Hər ayın 1-i: keçən ayın gəlir, xərc və qalığı.')" />
                </div>
            </flux:fieldset>

            <flux:select wire:model="notifyHour" :label="__('Göndərilmə saatı')" :description="__('Bakı vaxtı ilə, gündə bir dəfə.')" class="max-w-40">
                @foreach (config('finance.notify_hours') as $hour)
                    <flux:select.option :value="$hour">{{ sprintf('%02d:00', $hour) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit" variant="primary">{{ __('Yadda saxla') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
