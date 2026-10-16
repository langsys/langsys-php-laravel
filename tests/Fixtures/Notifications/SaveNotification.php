<?php

namespace Langsys\Laravel\Tests\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

/** A notification whose one line is a literal `__()`. */
class SaveNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return [RecordingChannel::class];
    }

    public function toRecording(object $notifiable): string
    {
        return __('Save');
    }
}
