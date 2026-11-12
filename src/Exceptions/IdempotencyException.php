<?php

namespace Toshkq93\Outbox\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rejections of the idempotency middleware; rendered by your exception handler like any
 * HttpException (JSON {"message": ...} for API requests).
 */
class IdempotencyException extends HttpException implements OutboxException
{
    public static function missing(string $header): self
    {
        return new self(400, "The {$header} header is required for this request.");
    }

    public static function invalid(string $header): self
    {
        return new self(400, "The {$header} header is invalid.");
    }

    public static function reused(string $header): self
    {
        return new self(422, "This {$header} was already used with a different request.");
    }

    public static function inProgress(string $header, int $retryAfter): self
    {
        return new self(
            409,
            "A request with this {$header} is still being processed. Retry later.",
            null,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
