<?php

namespace Toshkq93\Outbox\Serialization;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use Stringable;
use Toshkq93\Outbox\Contracts\PayloadSerializer;
use Toshkq93\Outbox\Exceptions\UnserializablePayload;
use UnitEnum;

/**
 * Turns events into plain JSON payloads that any consumer (PHP or not) can read, and back.
 *
 * Serialization: outboxPayload() if defined, else jsonSerialize(), else the public properties.
 * Models become their key, model collections a list of keys, enums their value (or name),
 * dates an ISO-8601 string with microseconds, Arrayable / JsonSerializable their array form.
 *
 * Deserialization: static fromOutboxPayload() if defined, else the constructor is called with
 * named arguments from the payload, converting values back by the parameter's declared type
 * (models are re-fetched by key, enums and dates are rebuilt). A class without a constructor
 * gets its public properties assigned.
 */
class DefaultPayloadSerializer implements PayloadSerializer
{
    public const DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function serialize(object $event): array
    {
        $data = match (true) {
            method_exists($event, 'outboxPayload') => $event->outboxPayload(),
            $event instanceof JsonSerializable => $event->jsonSerialize(),
            default => get_object_vars($event), // public properties only, called from outside the class
        };

        $normalized = $this->normalize($data, 'payload');

        if (! is_array($normalized)) {
            throw UnserializablePayload::at('payload', $data);
        }

        return $normalized;
    }

    public function deserialize(string $class, array $payload): object
    {
        if (method_exists($class, 'fromOutboxPayload')) {
            return $class::fromOutboxPayload($payload);
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            $instance = $reflection->newInstance();

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (! $property->isStatic() && ! $property->isReadOnly() && array_key_exists($property->getName(), $payload)) {
                    $property->setValue($instance, $this->hydrate($property->getType(), $payload[$property->getName()]));
                }
            }

            return $instance;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $payload)) {
                $arguments[$name] = $this->hydrate($parameter->getType(), $payload[$name]);
            } elseif ($parameter->isOptional()) {
                continue;
            } elseif ($parameter->allowsNull()) {
                $arguments[$name] = null;
            } else {
                throw UnserializablePayload::missingArgument($class, $name);
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }

    protected function normalize(mixed $value, string $path): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            is_array($value) => $this->normalizeArray($value, $path),
            $value instanceof Model => $value->getKey(),
            $value instanceof EloquentCollection => $value->modelKeys(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            $value instanceof Arrayable => $this->normalize($value->toArray(), $path),
            $value instanceof JsonSerializable => $this->normalize($value->jsonSerialize(), $path),
            $value instanceof Stringable => (string) $value,
            default => throw UnserializablePayload::at($path, $value),
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function normalizeArray(array $values, string $path): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $this->normalize($value, $path.'.'.$key);
        }

        return $values;
    }

    protected function hydrate(?ReflectionType $type, mixed $value): mixed
    {
        if ($value === null || ! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        $class = $type->getName();

        return match (true) {
            is_subclass_of($class, Model::class) => (new $class)->newQueryForRestoration($value)->firstOrFail(),
            is_subclass_of($class, BackedEnum::class) => $class::from($value),
            is_subclass_of($class, UnitEnum::class) => constant($class.'::'.$value),
            $class === DateTimeInterface::class, $class === CarbonInterface::class => Date::parse($value),
            is_subclass_of($class, CarbonInterface::class) => $class::parse($value),
            is_subclass_of($class, DateTimeInterface::class) => new $class($value),
            default => $value,
        };
    }
}
