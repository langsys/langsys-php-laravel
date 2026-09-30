<?php

namespace Langsys\Laravel\Tests\Fixtures\elsewhere;

use Langsys\SDK\Messages\TranslatesAs;

#[TranslatesAs('region')]
enum Region: string
{
    case North = 'North';
}
