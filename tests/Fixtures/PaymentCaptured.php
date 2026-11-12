<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

use Toshkq93\Outbox\Contracts\ShouldBeOutboxed;

/** An event that customises every part of its outbox message. */
class PaymentCaptured implements ShouldBeOutboxed
{
    public function __construct(private int $paymentId, private int $amount) {}

    public function outboxType(): string
    {
        return 'payment.captured';
    }

    public function outboxPayload(): array
    {
        return ['payment' => $this->paymentId, 'amount_cents' => $this->amount];
    }

    public function outboxHeaders(): array
    {
        return ['schema_version' => 2];
    }

    public function outboxAggregate(): array
    {
        return ['payment', $this->paymentId];
    }

    public function outboxPublisher(): string
    {
        return 'billing';
    }

    public static function fromOutboxPayload(array $payload): self
    {
        return new self($payload['payment'], $payload['amount_cents']);
    }

    public function paymentId(): int
    {
        return $this->paymentId;
    }

    public function amount(): int
    {
        return $this->amount;
    }
}
