<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

use Toshkq93\Outbox\Contracts\ShouldHandleOnce;
use Toshkq93\Outbox\Message;

class IssueInvoiceEntry implements ShouldHandleOnce
{
    public function handle(InvoiceIssued $event): void
    {
        Ledger::write(self::class, "invoice:{$event->invoiceId}");
    }

    public function onRaw(Message $message): void
    {
        Ledger::write(self::class, "raw:{$message->payload['invoice']}");
    }
}
