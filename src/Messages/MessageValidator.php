<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Validation\Validator;
use Langsys\Laravel\Support\ClientState;
use Langsys\SDK\Client;
use Langsys\SDK\Exception\ConfigurationException;
use Langsys\SDK\Messages\ServerMessage;
use Throwable;

/**
 * The validator Laravel builds with Langsys installed.
 *
 * **It never changes what Laravel renders.** The message bag keeps Laravel's own sentences, from
 * the app's own validation lines for the locale as Laravel reads them, so `$errors`, `@error`, the
 * 422 body and Livewire are untouched. What this adds
 * is structure: one entry per failed rule, built from the rule rather than from the rendered
 * string (MSG-9). Registration is `langsys:sync`'s: an entry never registers while a request is
 * served (FRM-2). Each entry is emitted to the core, which warns when a marker carries a value its
 * catalog translates (MSG-11).
 *
 * **Every entry built here is source.** A client renders `entry.template` through its own SDK
 * (MSG-5); only a JSON response's `message` is put in the request's language, by
 * `AttachServerMessages` (FRM-5). Translating here would put translated text where a redirect's
 * reader expects source text, starting with a client SDK that would look it up as a phrase.
 *
 * @internal
 */
final class MessageValidator extends Validator implements HasServerMessages
{
    /** @var list<ServerMessage> */
    private array $serverMessages = [];

    /** @var array<string, list<string>>|null The bag as the rules left it, before the first `after()` hook ran. */
    private ?array $ruleMessages = null;

    public function passes()
    {
        $this->ruleMessages = null;
        $passes = parent::passes();

        if (!$passes) {
            $this->_recordServerMessages();
        }

        return $passes;
    }

    /**
     * Laravel runs every rule, then the hooks, so the bag as the rules left it tells a hook's
     * message on a field from the messages that field's rules added.
     */
    public function after($callback)
    {
        if (is_array($callback) && !is_callable($callback)) {
            return parent::after($callback);
        }

        return parent::after(function ($validator) use ($callback) {
            $this->ruleMessages ??= $this->messages->messages();

            return $callback($validator);
        });
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
            $entries = ValidatorMessages::fromValidator($this, config('app.fallback_locale'), $this->ruleMessages);
            $this->serverMessages = $entries;

            // With no client to be had the entries still travel, in the source language.
            if (!ClientState::buildable()) {
                return;
            }

            $client = app(Client::class);

            // The locale this request is served in, not Client::getLocale(), which auto-detects
            // from $_SERVER and can spend an HTTP call working out the project's base locale.
            $client->setLocale(ClientState::locale());

            foreach ($entries as $entry) {
                $client->emitMessage($entry);
            }
        } catch (ConfigurationException $e) {
            // `require_credentials` asked for a missing key to fail the request, not to degrade it.
            throw $e;
        } catch (Throwable $e) {
            // A form must never break because this did. Laravel's messages stand either way, since
            // nothing above rewrites them.
            report($e);
        }
    }
}
