<?php

namespace Langsys\Laravel\Tests\Fixtures\app\Models;

use Langsys\SDK\Messages\TranslatableValues;

/** A set held in a model: its rows are read each time, so one added since the last sync counts. */
class Category implements TranslatableValues
{
    public static array $rows = ['Books', 'Music'];

    public static function translatableValues()
    {
        return ['category' => self::$rows];
    }
}
