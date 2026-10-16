<?php

namespace Langsys\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Langsys\Laravel\LangsysTranslator;

/**
 * @method static string translate(string $phrase, ?string $category = null, array $params = [], ?string $locale = null)
 * @method static array resolve(string $phrase, ?string $category = null, array $params = [], ?string $locale = null)
 * @method static array translateRich(string $sourceHtml, ?string $category = null, array $params = [], ?string $locale = null)
 * @method static array getCurrencies(?string $locale = null)
 * @method static array getCountries(?string $locale = null)
 * @method static array getDialCodes(?string $locale = null)
 * @method static array getLocalesFormat(string $format = '', ?string $locale = null)
 * @method static \Langsys\SDK\Client client()
 *
 * @see LangsysTranslator
 */
class Langsys extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LangsysTranslator::class;
    }
}
