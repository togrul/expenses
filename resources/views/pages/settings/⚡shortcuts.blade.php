<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Siri və qısayollar')] class extends Component {
    /**
     * The plain token, shown only right after it is created.
     */
    public ?string $newToken = null;

    public function generateToken(): void
    {
        $token = Str::random(40);

        $user = Auth::user();
        $user->forceFill(['shortcut_token' => hash('sha256', $token)])->save();

        $this->newToken = $token;
    }

    public function revokeToken(): void
    {
        Auth::user()->forceFill(['shortcut_token' => null])->save();
        $this->newToken = null;

        Flux::toast(variant: 'success', text: __('Qısayol açarı ləğv edildi.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Siri və qısayollar')" :subheading="__('Tətbiqi açmadan xərc yazın: “Hey Siri, xərc yaz” və ya telefonun arxasına iki dəfə toxunun.')">
        <div class="space-y-6">
            <div class="card space-y-4 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-ink">{{ __('Şəxsi açar') }}</div>
                        <div class="text-sm text-muted">
                            {{ auth()->user()->shortcut_token ? __('Aktivdir. İtirsəniz, yenisini yaradın — köhnəsi işləməyəcək.') : __('Hələ yaradılmayıb.') }}
                        </div>
                    </div>
                    <flux:badge :color="auth()->user()->shortcut_token ? 'green' : 'zinc'" size="sm">{{ auth()->user()->shortcut_token ? __('Aktiv') : __('Yoxdur') }}</flux:badge>
                </div>

                @if ($newToken)
                    <div x-data="{ copied: false }" class="space-y-2 rounded-xl border border-gold/30 bg-gold-soft p-4">
                        <div class="text-xs font-semibold text-gold">{{ __('Açarı indi kopyalayın — bir daha göstərilməyəcək') }}</div>
                        <code class="block break-all rounded-lg bg-canvas px-3 py-2 font-mono text-sm text-ink" data-test="shortcut-token">{{ $newToken }}</code>
                        <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($newToken)); copied = true">
                            <span x-text="copied ? @js(__('Kopyalandı')) : @js(__('Kopyala'))"></span>
                        </flux:button>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button variant="primary" wire:click="generateToken" icon="key">{{ auth()->user()->shortcut_token ? __('Yeni açar yarat') : __('Açar yarat') }}</flux:button>
                    @if (auth()->user()->shortcut_token)
                        <flux:button variant="ghost" wire:click="revokeToken">{{ __('Ləğv et') }}</flux:button>
                    @endif
                </div>
            </div>

            <div class="card p-5">
                <div class="mb-4 text-sm font-semibold text-ink">{{ __('“Xərc yaz” qısayolunu qurun') }}</div>
                <ol class="space-y-4 text-sm text-muted">
                    <li><span class="font-semibold text-ink">1.</span> {{ __('iPhone-da Qısayollar (Shortcuts) tətbiqini açın → sağ yuxarıda “+”. Adını “Xərc yaz” qoyun.') }}</li>
                    <li><span class="font-semibold text-ink">2.</span> {{ __('“Ask for Input” əlavə edin: növ — Number, sual — “Məbləğ?”.') }}</li>
                    <li>
                        <span class="font-semibold text-ink">3.</span> {{ __('“Get Contents of URL” əlavə edin. URL:') }}
                        <code class="mt-1 block break-all rounded-lg bg-surface-2 px-3 py-2 font-mono text-xs text-ink">{{ route('shortcut.categories') }}</code>
                        {{ __('Headers bölməsində: Authorization = Bearer AÇAR') }}
                    </li>
                    <li><span class="font-semibold text-ink">4.</span> {{ __('“Choose from List” əlavə edin (əvvəlki addımın nəticəsi), sual — “Kateqoriya?”.') }}</li>
                    <li>
                        <span class="font-semibold text-ink">5.</span> {{ __('Yenə “Get Contents of URL”. Method — POST, URL:') }}
                        <code class="mt-1 block break-all rounded-lg bg-surface-2 px-3 py-2 font-mono text-xs text-ink">{{ route('shortcut.transactions.store') }}</code>
                        {{ __('Headers: Authorization = Bearer AÇAR. Request Body — JSON: amount = “Provided Input”, category = “Chosen Item”.') }}
                    </li>
                    <li><span class="font-semibold text-ink">6.</span> {{ __('“Get Dictionary Value” (key: message) → “Show Notification”. Hazırdır: “Hey Siri, xərc yaz”.') }}</li>
                    <li><span class="font-semibold text-ink">7.</span> {{ __('Back Tap: Ayarlar → Əlçatımlılıq → Toxunma → Arxa toxunuş → İki dəfə toxunma → “Xərc yaz”.') }}</li>
                </ol>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
