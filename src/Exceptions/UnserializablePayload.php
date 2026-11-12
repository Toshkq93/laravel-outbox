<?php

namespace Toshkq93\Outbox\Exceptions;

use InvalidArgumentException;

class UnserializablePayload extends InvalidArgumentException implements OutboxException
{
    public static function at(string $path, mixed $value): self
    {
        return new self(sprintf(
            'Value of type [%s] at [%s] cannot be stored in the outbox payload. '
            .'Define outboxPayload() on the event or convert the value to a scalar or array.',
            get_debug_type($value),
            $path,
        ));
    }

    public static function missingArgument(string $class, string $parameter): self
    {
        return new self(sprintf(
            'Cannot rebuild [%s] from the outbox payload: required constructor argument [$%s] is missing. '
            .'Define static fromOutboxPayload(array $payload) on the event.',
            $class,
            $parameter,
        ));
    }
}
