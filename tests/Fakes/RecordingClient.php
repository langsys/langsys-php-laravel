<?php

namespace Langsys\Laravel\Tests\Fakes;

use Langsys\SDK\Client;
use ReflectionProperty;

/**
 * The REAL SDK Client, not a stand-in: every method runs the SDK's own code.
 * It only records the two lifecycle calls a boundary makes and exposes the
 * write decision, so a test can observe what one unit of work leaves behind
 * for the next. Build it through TestCase::offlineClient(), which keeps it off
 * the network.
 */
class RecordingClient extends Client
{
    /** @var list<string> */
    public array $calls = [];

    public function flushPendingRegistrations()
    {
        $this->calls[] = 'flush';

        return parent::flushPendingRegistrations();
    }

    public function resetRequestState()
    {
        $this->calls[] = 'reset';

        return parent::resetRequestState();
    }

    /** The stored decision itself — canWrite() would resolve a missing one over the network. */
    public function writeDecision(): ?bool
    {
        return (new ReflectionProperty(Client::class, 'writeEnabled'))->getValue($this);
    }

    /** As though this unit of work had already been authorized. */
    public function decideWrite(bool $enabled): void
    {
        (new ReflectionProperty(Client::class, 'writeEnabled'))->setValue($this, $enabled);
    }
}
