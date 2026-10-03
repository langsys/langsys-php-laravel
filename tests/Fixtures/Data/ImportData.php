<?php

namespace Langsys\Laravel\Tests\Fixtures\Data;

use Illuminate\Validation\Validator;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * A collection of nested DTOs labelled through laravel-data's `withValidator()` hook, as an
 * application does when laravel-data hands Laravel concrete keys (`items.0.phrase`) that a
 * wildcard label never reaches.
 */
class ImportData extends Data
{
    public function __construct(
        #[DataCollectionOf(ItemData::class)]
        public array $items,
    ) {
    }

    public static function withValidator(Validator $validator): void
    {
        foreach (array_keys($validator->getRules()) as $key) {
            if (preg_match('/^items\.\d+\.phrase$/', $key)) {
                $validator->addCustomAttributes([$key => 'phrase']);
            }
        }
    }
}
