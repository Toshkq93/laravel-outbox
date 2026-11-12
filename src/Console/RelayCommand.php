<?php

namespace Toshkq93\Outbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Attribute\AsCommand;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Relay\RelayResult;

#[AsCommand(name: 'outbox:relay')]
class RelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Process a single batch and exit}
        {--stop-when-empty : Exit as soon as no message is due}
        {--batch= : Messages claimed per batch (default: outbox.outbox.relay.batch_size)}
        {--sleep= : Seconds to sleep when no message is due (default: outbox.outbox.relay.sleep)}
        {--max-time=0 : Exit after this many seconds}
        {--max-messages=0 : Exit after processing this many messages}
        {--memory=128 : Exit when memory usage exceeds this many megabytes}';

    protected $description = 'Publish messages from the transactional outbox';

    protected bool $shouldQuit = false;

    public function handle(Relay $relay): int
    {
        $this->listenForSignals();

        $batch = (int) ($this->option('batch') ?? config('outbox.outbox.relay.batch_size', 100));
        $sleep = (float) ($this->option('sleep') ?? config('outbox.outbox.relay.sleep', 1));
        $owner = $relay->owner();
        $started = microtime(true);
        $processed = 0;

        if (! $this->option('once')) {
            $this->components->info("Relaying outbox messages as [{$owner}].");
        }

        while (true) {
            $result = $relay->runOnce($batch, $owner);
            $processed += $result->claimed;
            $this->report($result);

            if ($this->option('once') || ($result->isEmpty() && $this->option('stop-when-empty'))) {
                break;
            }

            if ($reason = $this->stopReason($started, $processed)) {
                $this->components->info("Stopping relay: {$reason}.");

                break;
            }

            if ($result->isEmpty()) {
                Sleep::usleep((int) ($sleep * 1_000_000));
            }
        }

        return self::SUCCESS;
    }

    protected function stopReason(float $started, int $processed): ?string
    {
        $maxTime = (int) $this->option('max-time');
        $maxMessages = (int) $this->option('max-messages');

        return match (true) {
            $this->shouldQuit => 'signal received',
            $maxTime > 0 && microtime(true) - $started >= $maxTime => 'max time reached',
            $maxMessages > 0 && $processed >= $maxMessages => 'max messages reached',
            memory_get_usage(true) / 1024 / 1024 >= (int) $this->option('memory') => 'memory limit reached',
            default => null,
        };
    }

    protected function report(RelayResult $result): void
    {
        if ($result->isEmpty()) {
            return;
        }

        $line = sprintf('Claimed %d: %d published', $result->claimed, $result->published);

        if ($result->failed > 0) {
            $line .= sprintf(', <fg=yellow>%d failed</>', $result->failed);
        }

        if ($result->dead > 0) {
            $line .= sprintf(', <fg=red>%d dead</>', $result->dead);
        }

        $this->line(sprintf('<fg=gray>%s</> %s', now()->format('Y-m-d H:i:s'), $line));

        if ($this->output->isVerbose()) {
            foreach ($result->errors as $messageId => $error) {
                $this->line("  <fg=yellow>{$messageId}</> {$error}");
            }
        }
    }

    /**
     * Finish the current batch on SIGTERM / SIGINT / SIGQUIT instead of dying mid-publish
     * (a killed relay is safe too: its lease expires and the batch is retried).
     */
    protected function listenForSignals(): void
    {
        if (! extension_loaded('pcntl') || ! defined('SIGTERM')) {
            return;
        }

        $this->trap([SIGTERM, SIGINT, SIGQUIT], function () {
            $this->shouldQuit = true;
        });
    }
}
