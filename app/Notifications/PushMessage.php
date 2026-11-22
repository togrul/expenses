<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A Web Push notification for the installed iPhone app (see public/sw.js).
 */
class PushMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $tag = null,
        public ?int $badge = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->lang('az')
            ->data(['url' => $this->url, 'badge' => $this->badge])
            ->options(['TTL' => 60 * 60 * 12, 'urgency' => 'normal']);

        if ($this->tag !== null) {
            $message->tag($this->tag)->renotify();
        }

        return $message;
    }
}
