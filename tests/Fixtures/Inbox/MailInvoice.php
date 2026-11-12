<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

use Illuminate\Contracts\Queue\ShouldQueue;
use Toshkq93\Outbox\Contracts\ShouldHandleOnce;
use Toshkq93\Outbox\Jobs\Middleware\DeduplicateMessage;

class MailInvoice implements ShouldHandleOnce, ShouldQueue
{
    public function handle(InvoiceIssued $event): void
    {
        Ledger::write(self::class, "mail:{$event->invoiceId}");
    }

    public function middleware(): array
    {
        return [new DeduplicateMessage];
    }
}
