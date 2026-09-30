<?php

namespace Langsys\Laravel\Console;

use Illuminate\Console\Command;
use Langsys\Laravel\Messages\FormRequestSource;
use Langsys\SDK\Client;
use Langsys\SDK\Messages\MessageCatalogCommand;
use Langsys\SDK\Migration\LegacyKeysSource;
use Throwable;

/**
 * MSG-7 in Laravel's console. Listing, registering and the exit codes are the core's
 * (`MessageCatalogCommand`); this names Laravel's sources and prints through Artisan.
 */
class MessagesCommand extends Command
{
    protected $signature = 'langsys:messages
        {--register : Register every listed template the Langsys catalog lacks}
        {--strict : Fail when a message cannot be listed ahead of time}';

    protected $description = 'List every validation message the app can send, and register them for translation';

    public function handle(): int
    {
        $sources = [FormRequestSource::fromRoutes($this->laravel['router'])];

        // The lang files carry what the migration cannot convert as it stands (MIG-4,
        // MIG-7). Building a Client without credentials throws, so only a configured app asks.
        if (config('langsys.enabled') && config('langsys.api_key')) {
            $legacy = $this->laravel->make(Client::class)->getLegacyKeys();

            if ($legacy !== null) {
                $sources[] = new LegacyKeysSource($legacy);
            }
        }

        $catalog = MessageCatalogCommand::collect($sources);
        $templates = $catalog->templates();

        $this->line(count($templates) . ' message templates');

        if ($this->output->isVerbose()) {
            foreach ($templates as $entry) {
                $this->line("  {$entry['template']}  <fg=gray>{$entry['source']}</>");
            }
        }

        foreach ($catalog->problems() as $problem) {
            $this->error('✗ ' . $problem);
        }

        // A message that cannot be listed is still sent, in the source language, so it is reported,
        // not failed, unless the app asks for no untranslated message ever.
        if ($catalog->hasProblems()) {
            $count = count($catalog->problems());
            $this->warn($count . ($count === 1 ? ' message cannot' : ' messages cannot') . ' be registered ahead of time; each is shown in the source language until it is');

            if ($this->option('strict')) {
                return self::FAILURE;
            }
        }

        if (!$this->option('register')) {
            return self::SUCCESS;
        }

        try {
            $client = $this->laravel->make(Client::class);
            $result = MessageCatalogCommand::register($catalog, $client);
        } catch (Throwable $e) {
            $this->error('Cannot register: ' . $e->getMessage());

            return self::FAILURE;
        }

        $category = $client->getConfig()->getMessagesCategory();

        $this->info($result['registered'] === 0
            ? "All {$result['skipped']} templates are already registered under \"$category\": nothing new to register"
            : "Registered {$result['registered']} templates under \"$category\"" . ($result['skipped'] > 0 ? " ({$result['skipped']} already registered)" : ''));

        return self::SUCCESS;
    }
}
