<?php

namespace Langsys\Laravel\Tests\Fixtures\Notifications;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Notifiable;

class Recipient implements HasLocalePreference
{
    use Notifiable;

    public function __construct(public string $name, private string $locale)
    {
    }

    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
