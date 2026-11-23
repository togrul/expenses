<?php

use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Illuminate\Support\Collection;
use Laravel\Passkeys\Passkey;
use Livewire\Component;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public ?int $passkeyToDelete = null;



    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {

    }

    /**
     * The signed-in user's passkeys (Face ID / Touch ID devices).
     *
     * @return Collection<int, Passkey>
     */
    #[Computed]
    public function passkeys(): Collection
    {
        return Auth::user()->passkeys()->latest()->get();
    }

    #[On('passkey-registered')]
    public function passkeyRegistered(): void
    {
        unset($this->passkeys);

        Flux::toast(variant: 'success', text: __('Passkey əlavə olundu. Növbəti dəfə Face ID ilə daxil ola bilərsiniz.'));
    }

    public function confirmPasskeyDeletion(int $passkeyId): void
    {
        $this->passkeyToDelete = Auth::user()->passkeys()->findOrFail($passkeyId)->id;

        Flux::modal('delete-passkey')->show();
    }

    public function deletePasskey(): void
    {
        Auth::user()->passkeys()->findOrFail($this->passkeyToDelete)->delete();

        $this->passkeyToDelete = null;
        unset($this->passkeys);

        Flux::modal('delete-passkey')->close();
        Flux::toast(variant: 'success', text: __('Passkey silindi.'));
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }


}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        @if (Features::enabled(Features::passkeys()))
            <section class="mt-12 space-y-4" aria-labelledby="passkeys-heading">
                <div>
                    <flux:heading id="passkeys-heading">{{ __('Face ID / Passkey') }}</flux:heading>
                    <flux:subheading>{{ __('Parolsuz, Face ID və ya Touch ID ilə daxil olun. Hər cihaz üçün ayrıca əlavə edin.') }}</flux:subheading>
                </div>

                <div class="divide-y divide-line rounded-2xl border border-line">
                    @forelse ($this->passkeys as $passkey)
                        <div class="flex items-center gap-3 px-4 py-3" wire:key="passkey-{{ $passkey->id }}">
                            <flux:icon name="finger-print" variant="outline" class="size-5 text-gold" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-ink">{{ $passkey->name }}</div>
                                <div class="text-xs text-faint">
                                    {{ __('Əlavə olunub: :date', ['date' => $passkey->created_at->format('d.m.Y')]) }}
                                    @if ($passkey->last_used_at)
                                        · {{ __('Son istifadə: :date', ['date' => $passkey->last_used_at->format('d.m.Y H:i')]) }}
                                    @endif
                                </div>
                            </div>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmPasskeyDeletion({{ $passkey->id }})" :aria-label="__('Sil')" />
                        </div>
                    @empty
                        <div class="px-4 py-5 text-sm text-faint">{{ __('Hələ passkey əlavə olunmayıb.') }}</div>
                    @endforelse
                </div>

                <div x-data="passkeyRegister(@js(__('iPhone')))" class="space-y-3">
                    <template x-if="supported">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <flux:input x-model="name" :placeholder="__('Cihazın adı (məs. iPhone)')" maxlength="60" class="sm:max-w-60" />
                            <flux:button variant="primary" icon="plus" x-on:click="register" x-bind:disabled="busy" data-test="add-passkey-button">
                                {{ __('Bu cihazı əlavə et') }}
                            </flux:button>
                        </div>
                    </template>
                    <p x-show="!supported" x-cloak class="text-sm text-faint">{{ __('Bu brauzer passkey-ləri dəstəkləmir.') }}</p>
                    <p x-show="error" x-text="error" x-cloak class="text-sm text-negative" role="alert"></p>
                </div>
            </section>

            <flux:modal name="delete-passkey" class="sheet-on-mobile w-full max-w-sm">
                <div class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Passkey silinsin?') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Bu cihazla Face ID girişi işləməyəcək. Parolla daxil olmaq mümkün qalır.') }}</flux:text>
                    </div>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Ləğv et') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="deletePasskey">{{ __('Sil') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </x-pages::settings.layout>

</section>
