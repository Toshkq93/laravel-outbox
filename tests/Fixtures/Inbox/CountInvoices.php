<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

/** Not idempotent: runs on every delivery. */
class CountInvoices
{
    public function handle(InvoiceIssued $event): void
    {
        Ledger::$calls[] = self::class;
    }
}
