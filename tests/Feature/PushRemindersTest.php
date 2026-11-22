<?php

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\RecurringOccurrence;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PushMessage;
use App\Services\PaymentReminders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 05:00:00', 'UTC'));
});

function subscribe(User $user): User
{
    $user->updatePushSubscription('https://web.push.apple.com/'.fake()->uuid(), 'p256dh-key', 'auth-token', 'aes128gcm');

    return $user;
}

test('reminders cover payments due today, tomorrow and overdue ones', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['counterparty' => 'Access Bank']);
    DebtPayment::factory()->for($debt)->dueOn('2026-09-17')->create(['amount' => 86700]);
    DebtPayment::factory()->for($debt)->dueOn('2026-09-10')->create(['amount' => 1000]);
    RecurringOccurrence::factory()
        ->for(RecurringPayment::factory()->for($user)->create(['name' => 'İnternet']))
        ->create(['due_on' => '2026-09-18', 'amount' => 3000]);
    DebtPayment::factory()->for($debt)->dueOn('2026-09-25')->create(['amount' => 5000]);

    $messages = collect(app(PaymentReminders::class)->for($user));

    expect($messages->pluck('tag')->all())->toBe(['due-today', 'due-tomorrow', 'overdue'])
        ->and($messages[0]->body)->toContain('Access Bank')
        ->and($messages[0]->url)->toBe(parse_url($debt->url(), PHP_URL_PATH))
        ->and($messages[1]->body)->toContain('İnternet')
        ->and($messages[0]->badge)->toBe(2);
});

test('switched off reminder types are not sent', function () {
    $user = User::factory()->create(['preferences' => ['notify_today' => false, 'notify_overdue' => false]]);
    $debt = Debt::factory()->for($user)->create();
    DebtPayment::factory()->for($debt)->dueOn('2026-09-17')->create();
    DebtPayment::factory()->for($debt)->dueOn('2026-09-10')->create();

    expect(app(PaymentReminders::class)->for($user))->toBeEmpty();
});

test('the monthly summary goes out on the first day of the month', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->income()->create(['amount' => 200000, 'occurred_on' => '2026-08-05']);
    Transaction::factory()->for($user)->expense()->create(['amount' => 50000, 'occurred_on' => '2026-08-06']);

    $messages = app(PaymentReminders::class)->for($user, CarbonImmutable::parse('2026-09-01'));

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->tag)->toBe('monthly-summary')
        ->and($messages[0]->body)->toContain('1 500,00');
});

test('the hourly command only notifies subscribed users at their hour, once a day', function () {
    Notification::fake();

    $atNine = subscribe(User::factory()->create());
    $atTen = subscribe(User::factory()->create(['preferences' => ['notify_hour' => 10]]));
    $unsubscribed = User::factory()->create();

    foreach ([$atNine, $atTen, $unsubscribed] as $user) {
        DebtPayment::factory()->for(Debt::factory()->for($user))->dueOn('2026-09-17')->create();
    }

    // 05:00 UTC is 09:00 in Baku.
    $this->artisan('finance:send-reminders')->assertSuccessful();
    $this->artisan('finance:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($atNine, PushMessage::class, 1);
    Notification::assertNothingSentTo($atTen);
    Notification::assertNothingSentTo($unsubscribed);
});

test('a device can subscribe and unsubscribe', function () {
    $user = User::factory()->create();
    $endpoint = 'https://web.push.apple.com/QGuQyavXut';

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BPk3', 'auth' => 'xyz'],
            'contentEncoding' => 'aes128gcm',
        ])
        ->assertNoContent();

    expect($user->pushSubscriptions()->sole()->endpoint)->toBe($endpoint);

    $this->actingAs($user)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertNoContent();

    expect($user->pushSubscriptions()->count())->toBe(0);
});

test('a subscription needs an https endpoint and keys', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://evil.test', 'keys' => []])
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});

test('notification settings are saved and a test push is sent', function () {
    Notification::fake();
    $user = subscribe(User::factory()->create());

    $this->actingAs($user)->get(route('notifications.edit'))->assertOk()->assertSee('Bildirişlər');

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('notifyTomorrow', false)
        ->set('notifyHour', 20)
        ->call('save')
        ->assertHasNoErrors()
        ->call('sendTest');

    expect($user->fresh()->preferences)->toMatchArray(['notify_tomorrow' => false, 'notify_hour' => 20]);
    Notification::assertSentTo($user, PushMessage::class, fn (PushMessage $message): bool => $message->tag === 'test');
});

test('the reminder hour must be one of the allowed hours', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::settings.notifications')
        ->set('notifyHour', 2)
        ->call('save')
        ->assertHasErrors('notifyHour');
});
