<?php

namespace Toshkq93\Outbox\Contracts;

use Toshkq93\Outbox\Exceptions\UnserializablePayload;

interface PayloadSerializer
{
    /**
     * Convert an event to a JSON-safe array.
     *
     * @return array<array-key, mixed>
     *
     * @throws UnserializablePayload
     */
    public function serialize(object $event): array;

    /**
     * Rebuild an event of the given class from a stored payload.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<array-key, mixed>  $payload
     * @return T
     */
    public function deserialize(string $class, array $payload): object;
}
