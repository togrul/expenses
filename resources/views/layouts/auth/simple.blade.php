<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink">
        <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(60%_50%_at_50%_0%,var(--gold-soft),transparent_70%)]"></div>

        <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 p-6 pt-[calc(1.5rem+env(safe-area-inset-top))] pb-[calc(1.5rem+env(safe-area-inset-bottom))] md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-8">
                <x-app-logo href="{{ route('dashboard') }}" wire:navigate.hover class="justify-center" />

                <div class="card flex flex-col gap-6 p-7">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
