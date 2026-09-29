<?php

namespace Langsys\Laravel\Tests\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

/** A notification channel that keeps what the notification rendered, as a mail channel would send it. */
class RecordingChannel
{
    /** @var list<string> */
    public static array $sent = [];

    public function send(object $notifiable, Notification $notification): void
    {
        self::$sent[] = $notification->toRecording($notifiable);
    }
}
