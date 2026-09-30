<?php

namespace Langsys\Laravel\Tests\Fixtures\app\Enums;

use Langsys\SDK\Messages\TranslatesAs;

#[TranslatesAs('status')]
enum OrderStatus: string
{
    case Shipped = 'shipped';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::Shipped => 'Shipped',
            self::OnHold  => 'On hold',
        };
    }
}
