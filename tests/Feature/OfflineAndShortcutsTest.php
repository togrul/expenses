<?php

use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function offlineEntry(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'type' => 'expense',
        'amount' => '12,50',
        'category_id' => null,
        'occurred_on' => '2026-11-20',
        'note' => 'metro',
        ...$overrides,
    ];
}

function shortcutToken(User $user): string
{
    $token = Str::random(40);
    $user->forceFill(['shortcut_token' => hash('sha256', $token)])->save();

    return $token;
}

test('offline entries are saved once even when the upload is retried', function () {
    $user = User::factory()->create();
    $food = $user->categories()->where('name', 'Qida')->sole();
    $entry = offlineEntry(['category_id' => $food->id]);

    $this->actingAs($user)
        ->postJson(route('transactions.offline'), ['transactions' => [$entry]])
        ->assertOk()
        ->assertJson(['synced' => [$entry['id']], 'rejected' => []]);

    $this->actingAs($user)
        ->postJson(route('transactions.offline'), ['transactions' => [$entry]])
        ->assertJson(['synced' => [$entry['id']]]);

    expect($user->transactions()->sole())
        ->amount->toBe(1250)
        ->category_id->toBe($food->id)
        ->note->toBe('metro');
});

test('invalid offline entries are rejected without blocking the others', function () {
    $user = User::factory()->create();
    $someoneElsesCategory = User::factory()->create()->categories()->where('type', 'expense')->firstOrFail();
    $good = offlineEntry();
    $badAmount = offlineEntry(['amount' => 'abc']);
    $foreignCategory = offlineEntry(['category_id' => $someoneElsesCategory->id]);

    $this->actingAs($user)
        ->postJson(route('transactions.offline'), ['transactions' => [$good, $badAmount, $foreignCategory]])
        ->assertJson(['synced' => [$good['id']], 'rejected' => [$badAmount['id'], $foreignCategory['id']]]);

    expect($user->transactions()->count())->toBe(1);
});

test('offline sync needs a signed in user', function () {
    $this->postJson(route('transactions.offline'), ['transactions' => [offlineEntry()]])->assertUnauthorized();
});

test('a shortcut records an expense with the matching category', function () {
    $user = User::factory()->create();
    $token = shortcutToken($user);

    $this->withToken($token)
        ->postJson(route('shortcut.transactions.store'), ['amount' => 8.5, 'category' => 'qida'])
        ->assertCreated()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Qida'));

    expect($user->transactions()->sole())
        ->amount->toBe(850)
        ->type->value->toBe('expense')
        ->category->name->toBe('Qida');
});

test('a shortcut lists only the user\'s active categories of the asked type', function () {
    $user = User::factory()->create();
    $token = shortcutToken($user);
    $user->categories()->where('name', 'Qida')->update(['archived_at' => now()]);

    $names = $this->withToken($token)->getJson(route('shortcut.categories'))->assertOk()->json();

    expect($names)->not->toContain('Qida')
        ->and($names)->toEqual($user->categories()->where('type', 'expense')->whereNull('archived_at')->ordered()->pluck('name')->all());
});

test('shortcut endpoints reject a missing or wrong token', function () {
    shortcutToken(User::factory()->create());

    $this->postJson(route('shortcut.transactions.store'), ['amount' => '5'])->assertUnauthorized();
    $this->withToken('wrong')->getJson(route('shortcut.categories'))->assertUnauthorized();
});

test('the shortcut token can be created and revoked in settings', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::settings.shortcuts')->call('generateToken');
    $token = $component->get('newToken');

    expect($user->fresh()->shortcut_token)->toBe(hash('sha256', $token));

    $component->call('revokeToken');

    expect($user->fresh()->shortcut_token)->toBeNull();
});
