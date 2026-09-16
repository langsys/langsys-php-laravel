<?php

namespace Langsys\Laravel\Tests\Messages;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Langsys\Laravel\Tests\TestCase;

/**
 * Keep mode is the default, and in it this package is inert: Laravel's validator, Laravel's
 * wording, nothing emitted. The assertions are deliberately about identity rather than behaviour —
 * the validator Laravel builds must be Laravel's own class, because anything else is this package
 * deciding something in a mode where it has no business deciding anything.
 */
class KeepModeTest extends TestCase
{
    public function testTheDefaultModeIsKeep(): void
    {
        $this->assertSame('keep', config('langsys.localization'));
    }

    public function testLaravelBuildsItsOwnValidator(): void
    {
        $this->assertSame(LaravelValidator::class, get_class(Validator::make([], ['name' => 'required'])));
    }

    public function testMessagesAreLaravelsOwn(): void
    {
        $validator = Validator::make([], ['cc_number' => 'required'], [], ['cc_number' => 'card number']);

        $this->assertSame('The card number field is required.', $validator->errors()->first('cc_number'));
    }
}
