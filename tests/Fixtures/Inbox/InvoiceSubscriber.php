<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

use Toshkq93\Outbox\Contracts\ShouldHandleOnce;

class InvoiceSubscriber implements ShouldHandleOnce
{
    public function archive(InvoiceIssued $event): void
    {
        Ledger::write(self::class, "archive:{$event->invoiceId}");
    }

    public function subscribe(): array
    {
        return [InvoiceIssued::class => 'archive'];
    }
}
