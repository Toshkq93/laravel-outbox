<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

use Toshkq93\Outbox\Contracts\Publisher;
use Toshkq93\Outbox\Message;

/** Appends "message_id owner" lines to a file; used by the multi-process relay test. */
class FilePublisher implements Publisher
{
    public function __construct(public array $config) {}

    public function publish(Message $message): void
    {
        usleep(random_int(100, 2000)); // widen the race window

        file_put_contents($this->config['path'], $message->messageId.' '.$this->config['owner'].PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
