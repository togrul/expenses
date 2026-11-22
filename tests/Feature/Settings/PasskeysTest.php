<?php

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

function addPasskey(User $user, string $name = 'iPhone'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => fake()->uuid(),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
}

test('the login page offers passkey sign in', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-test="passkey-login-button"', false)
        ->assertSee('autocomplete="email webauthn"', false);
});

test('the security page lists the user passkeys', function () {
    $user = User::factory()->create();
    addPasskey($user, 'Mənim iPhone');
    addPasskey(User::factory()->create(), 'Başqasının iPhone');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Mənim iPhone')
        ->assertDontSee('Başqasının iPhone');
});

test('a user can delete their own passkey', function () {
    $user = User::factory()->create();
    $passkey = addPasskey($user);

    Livewire::actingAs($user)
        ->test('pages::settings.security')
        ->call('confirmPasskeyDeletion', $passkey->id)
        ->call('deletePasskey')
        ->assertHasNoErrors();

    expect($user->passkeys()->count())->toBe(0);
});

test('a user cannot delete someone else\'s passkey', function () {
    $passkey = addPasskey(User::factory()->create());

    expect(fn () => Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.security')
        ->call('confirmPasskeyDeletion', $passkey->id))
        ->toThrow(ModelNotFoundException::class);

    expect($passkey->fresh())->not->toBeNull();
});
