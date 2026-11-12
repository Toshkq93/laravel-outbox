<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

use Toshkq93\Outbox\Contracts\ShouldHandleOnce;

/** Custom method and a stable consumer name. */
class NotifyAccounting implements ShouldHandleOnce
{
    public static function inboxConsumer(): string
    {
        return 'accounting.notify';
    }

    public function onIssued(InvoiceIssued $event): void
    {
        Ledger::write(self::class, "accounting:{$event->invoiceId}");
    }

    public function onRaw(string $event, array $payload): void
    {
        Ledger::write(self::class, "raw:wildcard:{$event}");
    }
}
