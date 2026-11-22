<?php

use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('app pages ship the installable iPhone app metadata', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
        ->assertSee('name="apple-mobile-web-app-capable" content="yes"', false)
        ->assertSee('viewport-fit=cover', false);
});

test('the web app manifest is standalone and its icons exist', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toStartWith('/');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }

    expect(public_path('sw.js'))->toBeFile()
        ->and(public_path('offline.html'))->toBeFile();
});

test('the quick add sheet offers the most used categories of the chosen type', function () {
    $user = User::factory()->create();
    $food = $user->categories()->where('name', 'Qida')->sole();
    $other = $user->categories()->where('type', 'expense')->whereKeyNot($food->id)->firstOrFail();

    Transaction::factory()->for($user)->count(3)->create(['type' => 'expense', 'category_id' => $food->id, 'occurred_on' => today()]);
    Transaction::factory()->for($user)->create(['type' => 'expense', 'category_id' => $other->id, 'occurred_on' => today()]);
    Transaction::factory()->for($user)->create(['type' => 'expense', 'category_id' => $other->id, 'occurred_on' => today()->subDays(120)]);
    Transaction::factory()->for($user)->count(5)->create(['type' => 'expense', 'category_id' => $other->id, 'occurred_on' => today()->subDays(200)]);

    $component = Livewire::actingAs($user)->test('transaction-modal')->call('create');

    expect($component->instance()->quickCategories->pluck('id')->all())->toBe([$food->id, $other->id]);

    $component->set('type', 'income');

    expect($component->instance()->quickCategories)->toBeEmpty();
});
