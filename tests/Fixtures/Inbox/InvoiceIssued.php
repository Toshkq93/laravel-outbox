<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

class InvoiceIssued
{
    public function __construct(public int $invoiceId) {}
}
