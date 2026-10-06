<?php

namespace Langsys\Laravel\Messages;

use Langsys\SDK\Messages\ServerMessage;

/**
 * A validator that carries the entries of its failure (MSG-1, MSG-9): one per failed rule, built
 * from the rule rather than from the rendered text. Every validator Laravel builds with this package
 * installed implements it, so an app with its own error envelope reads the entries from a
 * `ValidationException`'s validator and composes them itself:
 *
 *     if ($e->validator instanceof HasServerMessages) {
 *         foreach ($e->validator->serverMessages() as $entry) { … }
 *     }
 */
interface HasServerMessages
{
    /** @return list<ServerMessage> */
    public function serverMessages(): array;
}
