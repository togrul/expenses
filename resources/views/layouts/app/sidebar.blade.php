<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body
        class="min-h-screen bg-canvas text-ink"
        x-data
        x-on:keydown.window="
            if ($event.key.toLowerCase() === 'n' && ! $event.metaKey && ! $event.ctrlKey && ! $event.altKey
                && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable
                && ! document.querySelector('dialog[open]')) {
                $event.preventDefault();
                Livewire.dispatch('open-transaction-modal');
            }
        "
    >
        @php($overdueCount = app(\App\Services\StatsService::class)->overdue(auth()->user())['count'])
        <div x-data x-init="window.syncAppBadge?.({{ $overdueCount }})" class="hidden"></div>

        <flux:sidebar sticky collapsible="mobile" class="border-e border-line bg-canvas px-4 py-5 max-lg:pt-[calc(1.25rem+env(safe-area-inset-top))] max-lg:pb-[calc(1.25rem+env(safe-area-inset-bottom))] lg:w-64">
            <flux:sidebar.header class="mb-4 px-1">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <button
                type="button"
                x-on:click="Livewire.dispatch('open-transaction-modal')"
                class="mb-6 flex w-full cursor-pointer items-center gap-2.5 rounded-xl bg-ink px-3.5 py-2.5 text-sm font-semibold text-canvas shadow-sm transition duration-200 hover:opacity-90"
            >
                <flux:icon name="plus" variant="micro" />
                {{ __('Yeni əməliyyat') }}
                <kbd class="ms-auto hidden rounded-md border border-current/20 px-1.5 font-sans text-[10px] font-medium opacity-60 lg:inline">N</kbd>
            </button>

            <div class="eyebrow mb-2 px-3">{{ __('Menyu') }}</div>
            <nav class="flex flex-col gap-0.5" aria-label="{{ __('Əsas menyu') }}">
                @foreach (\App\Support\Navigation::items() as $item)
                    @php($isCurrent = request()->routeIs($item['active']))
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @if ($isCurrent) aria-current="page" @endif
                        @class([
                            'group relative flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors duration-200',
                            'bg-surface text-ink shadow-[var(--shadow-card)] ring-1 ring-line' => $isCurrent,
                            'text-muted hover:bg-surface/60 hover:text-ink' => ! $isCurrent,
                        ])
                    >
                        <flux:icon :name="$item['icon']" variant="outline" @class(['size-[18px] transition-colors', 'text-gold' => $isCurrent, 'text-faint group-hover:text-muted' => ! $isCurrent]) />
                        {{ $item['label'] }}
                        @if ($item['route'] === 'upcoming.index' && $overdueCount > 0)
                            <span class="ms-auto rounded-full bg-negative/15 px-1.5 py-0.5 text-[10px] font-bold text-negative num" title="{{ __('Gecikmiş ödənişlər') }}">{{ $overdueCount }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <flux:spacer />

            <a
                href="{{ route('finance.edit') }}"
                wire:navigate
                @class([
                    'mb-3 flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors duration-200',
                    'bg-surface text-ink ring-1 ring-line' => request()->routeIs('finance.edit', 'notifications.edit', 'profile.edit', 'security.edit', 'appearance.edit'),
                    'text-muted hover:bg-surface/60 hover:text-ink' => ! request()->routeIs('finance.edit', 'notifications.edit', 'profile.edit', 'security.edit', 'appearance.edit'),
                ])
            >
                <flux:icon name="cog-6-tooth" variant="outline" class="size-[18px] text-faint" />
                {{ __('Ayarlar') }}
            </a>

            <div class="border-t border-line pt-3">
                <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
            </div>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="sticky top-0 z-30 border-b border-line bg-canvas/85 pt-[env(safe-area-inset-top)] backdrop-blur-xl lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate class="ms-2" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('finance.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        {{-- Mobile bottom tab bar --}}
        <nav class="fixed inset-x-3 bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-40 grid grid-cols-6 items-center rounded-2xl border border-line-strong bg-surface/90 p-1 shadow-2xl shadow-black/20 backdrop-blur-xl lg:hidden" aria-label="{{ __('Əsas menyu') }}">
            @foreach (collect(\App\Support\Navigation::items())->where('mobile', true)->values() as $index => $item)
                @if ($index === 2)
                    <button
                        type="button"
                        x-on:click="Livewire.dispatch('open-transaction-modal')"
                        class="mx-auto flex size-12 cursor-pointer items-center justify-center rounded-2xl bg-gradient-to-br from-[#ecd8a6] via-[#d4b06a] to-[#a8823d] text-zinc-950 shadow-[inset_0_1px_0_rgb(255_255_255/0.5),0_8px_20px_-8px_rgb(212_176_106/0.7)] transition active:scale-95"
                        aria-label="{{ __('Yeni əməliyyat') }}"
                    >
                        <flux:icon name="plus" class="size-6" />
                    </button>
                @endif
                @php($isCurrent = request()->routeIs($item['active']))
                <a href="{{ route($item['route']) }}" wire:navigate @if ($isCurrent) aria-current="page" @endif @class([
                    'relative flex min-h-12 flex-col items-center justify-center gap-0.5 rounded-xl text-[10px] font-medium transition-colors',
                    'bg-gold-soft text-gold' => $isCurrent,
                    'text-faint' => ! $isCurrent,
                ])>
                    <flux:icon :name="$item['icon']" variant="outline" class="size-5" />
                    {{ $item['short'] }}
                    @if ($item['route'] === 'upcoming.index' && $overdueCount > 0)
                        <span class="absolute end-3 top-1.5 size-2 rounded-full bg-negative"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        <x-selection-bar />

        <livewire:transaction-modal />
        <livewire:pay-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
