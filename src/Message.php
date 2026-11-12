<?php

namespace Toshkq93\Outbox;

/**
 * An outbox message as it travels to consumers: detached from the database row,
 * so it survives pruning and can be serialized onto any queue or broker.
 */
final class Message
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $type,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly ?string $aggregateType = null,
        public readonly ?string $aggregateId = null,
        public readonly ?string $createdAt = null,
    ) {}

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[$name] ?? $default;
    }

    public function correlationId(): string
    {
        return $this->header('correlation_id', $this->messageId);
    }

    public function causationId(): ?string
    {
        return $this->header('causation_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'type' => $this->type,
            'payload' => $this->payload,
            'headers' => $this->headers,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: $data['message_id'],
            type: $data['type'],
            payload: $data['payload'] ?? [],
            headers: $data['headers'] ?? [],
            aggregateType: $data['aggregate_type'] ?? null,
            aggregateId: isset($data['aggregate_id']) ? (string) $data['aggregate_id'] : null,
            createdAt: $data['created_at'] ?? null,
        );
    }
}
