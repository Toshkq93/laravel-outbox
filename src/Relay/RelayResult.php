<?php

namespace Toshkq93\Outbox\Relay;

final class RelayResult
{
    /**
     * @param  array<string, string>  $errors  message_id => error
     */
    public function __construct(
        public readonly int $claimed = 0,
        public readonly int $published = 0,
        public readonly int $failed = 0,
        public readonly int $dead = 0,
        public readonly array $errors = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->claimed === 0;
    }
}
