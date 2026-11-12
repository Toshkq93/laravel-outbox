<?php

namespace Toshkq93\Outbox\Exceptions;

use LogicException;

class TransactionRequired extends LogicException implements OutboxException
{
    public static function forType(string $type, ?string $connection): self
    {
        return new self(sprintf(
            'Outbox message [%s] must be recorded inside a database transaction on connection [%s]: '
            .'without one it is not atomic with your data. Wrap the write in DB::transaction() '
            .'or disable outbox.outbox.strict.',
            $type,
            $connection ?? 'default',
        ));
    }
}
