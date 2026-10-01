<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Validation\Validator;
use Langsys\Laravel\Support\ClientState;
use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Langsys\SDK\Messages\ServerMessage;
use Throwable;

/**
 * The validator Laravel builds with Langsys installed.
 *
 * **It never changes what Laravel renders.** The message bag keeps Laravel's own sentences, in the
 * source language, so `$errors`, `@error`, the 422 body and Livewire are untouched. What this adds
 * is structure: one entry per failed rule, built from the rule rather than from the rendered
 * string (MSG-9). Registration is `langsys:sync`'s; the one runtime registration is a sentence
 * built from a declared value the last sync did not see, sent after the response (FRM-7, MSG-8).
 *
 * **Every entry built here is source.** A client renders `entry.template` through its own SDK
 * (MSG-5); only a JSON response's `message` is put in the request's language, by
 * `AttachServerMessages` (FRM-5). Translating here would put translated text where a redirect's
 * reader expects source text, starting with a client SDK that would look it up as a phrase.
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
            $this->serverMessages = $entries;

            // With no client to be had the entries still travel, in the source language.
            if (!ClientState::buildable()) {
                return;
            }

            $client = app(Client::class);

            // The app locale, not Client::getLocale(), which auto-detects from $_SERVER and can
            // spend an HTTP call working out the project's base locale.
            $client->setLocale(LocaleDetector::normalize(app()->getLocale()));

            foreach ($entries as $entry) {
                $client->emitMessage($entry);
            }
        } catch (Throwable $e) {
            // A form must never break because this did. Laravel's messages stand either way, since
            // nothing above rewrites them.
            report($e);
        }
    }
}
