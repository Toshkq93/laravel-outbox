<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

use RuntimeException;
use Toshkq93\Outbox\Contracts\Publisher;
use Toshkq93\Outbox\Message;

class RecordingPublisher implements Publisher
{
    /** @var list<Message> */
    public static array $published = [];

    /** @var array<string, true> types that fail to publish */
    public static array $failing = [];

    public function __construct(public array $config = []) {}

    public static function reset(): void
    {
        static::$published = [];
        static::$failing = [];
    }

    public function publish(Message $message): void
    {
        if (isset(static::$failing[$message->type])) {
            throw new RuntimeException("Broker rejected {$message->type}");
        }

        static::$published[] = $message;
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_map(fn (Message $message) => $message->type, static::$published);
    }
}
