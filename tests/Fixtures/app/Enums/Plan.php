<?php

namespace Langsys\Laravel\Tests\Fixtures\app\Enums;

use Langsys\SDK\Messages\TranslatesAs;

#[TranslatesAs('plan')]
enum Plan: string
{
    case Basic = 'Basic';
    case Pro = 'Pro';
}
