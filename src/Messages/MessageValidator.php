<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Validation\Validator;
use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Langsys\SDK\Messages\ServerMessage;
use Throwable;

/**
 * The validator Laravel builds in migrate mode.
 *
 * **It never changes what Laravel renders.** The message bag keeps Laravel's own sentences, in the
 * source language, so `$errors`, `@error`, the 422 body and Livewire are untouched. What this adds
 * is structure and registration: one entry per failed rule, built from the rule rather than from
 * the rendered string (MSG-9), and a template the catalog does not list is registered after the
 * response on the existing flush path (MSG-8).
 *
 * **The server never emits Langsys-translated text as part of this feature.** The server registers
 * source phrases, the API machine-translates them, and the client renders `entry.template` through
 * `t()` (MSG-5), falling back to `entry.message` — which is the base-locale fill, never a
 * translation. Translating here would put translated text where every reader expects source text,
 * starting with a client SDK that would then register it as a new phrase.
 *
 * @internal
 */
final class MessageValidator extends Validator
{
    /** @var list<ServerMessage> */
    private array $serverMessages = [];

    public function passes()
    {
        $passes = parent::passes();

        if (!$passes) {
            $this->_recordServerMessages();
        }

        return $passes;
    }

    /**
     * The entries behind this failure, for the response envelope (MSG-1).
     *
     * @return list<ServerMessage>
     */
    public function serverMessages(): array
    {
        return $this->serverMessages;
    }

    private function _recordServerMessages(): void
    {
        try {
            $entries = ValidatorMessages::fromValidator($this, config('app.fallback_locale'));
            $client = app(Client::class);

            // The app locale, not Client::getLocale(), which auto-detects from $_SERVER and can
            // spend an HTTP call working out the project's base locale.
            $client->setLocale(LocaleDetector::normalize(app()->getLocale()));

            foreach ($entries as $entry) {
                $client->emitMessage($entry);
            }

            $this->serverMessages = $entries;
        } catch (Throwable $e) {
            // A form must never break because this did. Laravel's messages stand either way, since
            // nothing above rewrites them.
            report($e);
        }
    }
}
