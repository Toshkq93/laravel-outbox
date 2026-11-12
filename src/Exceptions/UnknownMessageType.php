<?php

namespace Toshkq93\Outbox\Exceptions;

use InvalidArgumentException;

class UnknownMessageType extends InvalidArgumentException implements OutboxException
{
    public static function make(string $type): self
    {
        return new self(sprintf(
            'Outbox message type [%s] is neither an alias in config(\'outbox.types\') nor an existing class.',
            $type,
        ));
    }
}
