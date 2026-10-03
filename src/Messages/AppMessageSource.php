<?php

namespace Langsys\Laravel\Messages;

use Langsys\Laravel\Support\AppMessageDiscovery;
use Langsys\SDK\Messages\HasAppMessageTemplate;
use Langsys\SDK\Messages\MessageCatalog;
use Langsys\SDK\Messages\MessageSource;

/**
 * MSG-7's app messages — an API error, a notice — listed once each under the messages category
 * with their template and code, through the core's `MessageCatalog::addMessage()`, which builds a
 * class without its constructor and checks its markers against its declared properties. An enum
 * lists each of its cases. A class that is also a Laravel validation rule keeps its per-field
 * listing (FRM-2).
 */
final class AppMessageSource implements MessageSource
{
    /** @param  list<class-string>  $classes */
    public function __construct(private readonly array $classes)
    {
    }

    public static function discovered(): self
    {
        return new self(AppMessageDiscovery::classes());
    }

    public function collect(MessageCatalog $catalog)
    {
        foreach ($this->classes as $class) {
            $source = class_basename($class);

            // A backed enum declares one message per case, as a value set declares one value per case.
            if (enum_exists($class) && is_subclass_of($class, HasAppMessageTemplate::class)) {
                foreach ($class::cases() as $case) {
                    $catalog->addMessage($case, $source);
                }

                continue;
            }

            // A Laravel validation rule keeps its per-field listing (FRM-2), and is not listed twice.
            if (class_exists($class) && is_subclass_of($class, HasAppMessageTemplate::class) && !AppMessageDiscovery::isDeclaration($class)) {
                continue;
            }

            $catalog->addMessage($class, $source);
        }
    }
}
