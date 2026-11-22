<?php

use App\Actions\Recurring\GenerateOccurrences;
use App\Services\UpcomingService;
use App\Support\UpcomingItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /**
     * Limit to items due within this many days (overdue always included). Null = full horizon.
     */
    public ?int $days = null;

    public bool $includeUndated = true;

    public bool $searchable = false;

    public string $search = '';

    public function mount(GenerateOccurrences $generateOccurrences): void
    {
        $generateOccurrences->ensureForUser(Auth::user());
    }

    /**
     * @return array<string, Collection<int, UpcomingItem>>
     */
    #[Computed]
    public function groups(): array
    {
        $until = $this->days !== null ? CarbonImmutable::today()->addDays($this->days) : null;

        $groups = app(UpcomingService::class)->grouped(Auth::user(), $until, $this->includeUndated);
        $term = mb_strtolower(trim($this->search));

        if ($term === '') {
            return $groups;
        }

        return array_map(fn (Collection $items): Collection => $items->filter(
            fn (UpcomingItem $item): bool => str_contains(mb_strtolower($item->title.' '.$item->subtitle), $term),
        )->values(), $groups);
    }

    #[On('payment-updated')]
    public function refreshList(): void
    {
        unset($this->groups);
    }
}; ?>

<div class="space-y-6">
    @php($isEmpty = collect($this->groups)->every(fn ($items) => $items->isEmpty()))

    @if ($searchable)
        <x-search-input :placeholder="__('Ad, bank, kateqoriya…')" class="w-full sm:w-72" />
    @endif

    @if ($isEmpty && trim($search) !== '')
        <x-empty-state icon="magnifying-glass" :heading="__('“:q” üzrə heç nə tapılmadı', ['q' => trim($search)])" />
    @elseif ($isEmpty)
        <x-empty-state icon="check-circle" :heading="__('Qarşıda ödəniş yoxdur')" :text="__('Borc hissələri və daimi ödənişlər burada görünəcək.')" />
    @endif

    @unless ($isEmpty)
        <x-select-all :entries="collect($this->groups)->flatten(1)->map(fn ($i) => [$i->key(), $i->amount, $i->title])->values()->all()" class="ms-1" />
    @endunless

    @foreach ($this->groups as $group => $items)
        @continue($items->isEmpty())

        <section wire:key="group-{{ $group }}">
            <div class="mb-2.5 flex items-center justify-between px-1">
                <div class="flex items-center gap-2">
                    <span @class(['eyebrow', '!text-negative' => $group === 'overdue', '!text-gold' => $group === 'today'])>{{ \App\Services\UpcomingService::groupLabel($group) }}</span>
                    <span class="rounded-full bg-line px-1.5 text-[10px] font-semibold text-muted num">{{ $items->count() }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <x-money :amount="$items->sum('amount')" class="text-xs font-medium text-faint" />
                    <x-select-all :entries="$items->map(fn ($i) => [$i->key(), $i->amount, $i->title])->values()->all()" :label="__('Qrupu seç')" class="!py-0" />
                </div>
            </div>

            <div class="space-y-2">
                @foreach ($items as $item)
                    <x-payment-row
                        wire:key="upcoming-{{ $item->key() }}"
                        :kind="$item->kind"
                        :id="$item->id"
                        :title="$item->title"
                        :subtitle="$item->subtitle"
                        :due-on="$item->dueOn"
                        :amount="$item->amount"
                        :remind-days-before="$item->remindDaysBefore"
                        :href="$item->url"
                    >
                        <x-slot name="actions">
                            @if ($item->kind === \App\Support\UpcomingItem::Occurrence)
                                <flux:dropdown align="end">
                                    <flux:button size="xs" variant="subtle" icon="ellipsis-vertical" :aria-label="__('Əməliyyatlar')" />
                                    <flux:menu>
                                        <flux:menu.item icon="forward" x-on:click="$dispatch('skip-item', { id: {{ $item->id }}, skip: true })">
                                            {{ __('Bu dəfə keç') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </x-slot>
                    </x-payment-row>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
