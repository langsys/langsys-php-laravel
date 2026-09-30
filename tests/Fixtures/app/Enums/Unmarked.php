<?php

namespace Langsys\Laravel\Tests\Fixtures\app\Enums;

/** A backed enum no one declared for a placeholder: its values are data, never words to translate. */
enum Unmarked: string
{
    case One = 'one';
}
