<?php

namespace Toshkq93\Outbox\Idempotency;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The part of a response that is stored and replayed.
 */
final class StoredResponse
{
    /**
     * @param  array<string, list<string|null>>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * @param  list<string>  $exceptHeaders  case-insensitive; Set-Cookie is never stored
     */
    public static function fromResponse(SymfonyResponse $response, array $exceptHeaders = []): self
    {
        $except = array_map('strtolower', $exceptHeaders);

        $headers = array_filter(
            $response->headers->allPreserveCaseWithoutCookies(),
            fn (string $name) => ! in_array(strtolower($name), $except, true),
            ARRAY_FILTER_USE_KEY,
        );

        return new self($response->getStatusCode(), $headers, (string) $response->getContent());
    }

    public function toResponse(): Response
    {
        return new Response($this->body, $this->status, $this->headers);
    }

    /**
     * @return array{status: int, headers: array<string, list<string|null>>, body: string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'headers' => $this->headers, 'body' => $this->body];
    }

    /**
     * @param  array{status: int, headers: array<string, list<string|null>>, body: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['status'], $data['headers'], $data['body']);
    }
}
