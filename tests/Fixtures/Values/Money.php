<?php

namespace Langsys\Laravel\Tests\Fixtures\Values;

/** A value an app prints through `Lang::stringable()`. */
class Money
{
    public function __construct(public int $cents)
    {
    }
}
