<?php

namespace Langsys\Laravel\Tests\Fixtures\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return [RecordingChannel::class];
    }

    public function toRecording(object $notifiable): string
    {
        return __('messages.welcome', ['name' => $notifiable->name]);
    }
}
