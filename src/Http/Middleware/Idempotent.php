<?php

namespace Toshkq93\Outbox\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Toshkq93\Outbox\Events\IdempotencyKeyConflict;
use Toshkq93\Outbox\Events\IdempotentRequestReplayed;
use Toshkq93\Outbox\Exceptions\IdempotencyException;
use Toshkq93\Outbox\Idempotency\AttemptStatus;
use Toshkq93\Outbox\Idempotency\Idempotency;
use Toshkq93\Outbox\Idempotency\StoredResponse;
use Toshkq93\Outbox\Support\PackageEvents;

/**
 * Stripe-style idempotency keys:
 *
 *  - first request with a key: runs, its response is stored (see shouldStore());
 *  - repeat with the same key and the same request: the stored response, with Idempotent-Replayed: true;
 *  - same key, different request (method, path, query, body): 422;
 *  - same key while the first request is still running: 409 with Retry-After;
 *  - 5xx, exceptions and release_statuses: nothing stored, the key can be retried.
 *
 * Route parameters: 'idempotent:required', 'idempotent:optional', 'idempotent:ttl=3600'
 * (combine with a comma), or Idempotent::using(required: true, ttl: 3600).
 */
class Idempotent
{
    public function __construct(
        protected Config $config,
        protected Idempotency $idempotency,
    ) {}

    public static function using(?bool $required = null, ?int $ttl = null): string
    {
        $options = array_filter([
            $required === null ? null : ($required ? 'required' : 'optional'),
            $ttl === null ? null : "ttl={$ttl}",
        ]);

        return static::class.($options === [] ? '' : ':'.implode(',', $options));
    }

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $config = $this->config->get('outbox.idempotency');

        if (! ($config['enabled'] ?? true) || ! in_array($request->getMethod(), $config['methods'] ?? [], true)) {
            return $next($request);
        }

        [$required, $ttl] = $this->options($options, $config);
        $header = $config['header'] ?? 'Idempotency-Key';
        $key = $request->headers->get($header);

        if ($key === null || $key === '') {
            if ($required) {
                throw IdempotencyException::missing($header);
            }

            return $next($request);
        }

        // The length check holds whatever key_pattern allows: the key column is 255 characters.
        if (strlen($key) > 255 || ! preg_match($config['key_pattern'] ?? '/\A[\x21-\x7E]{1,255}\z/', $key)) {
            throw IdempotencyException::invalid($header);
        }

        $fingerprint = $this->idempotency->fingerprint($request);
        $scope = $this->idempotency->scope($request);
        $store = $this->idempotency->store();
        $attempt = $store->acquire(
            $scope,
            $key,
            $fingerprint,
            max(1, (int) ($config['lock'] ?? 60)),
            $ttl,
        );

        if ($attempt->status !== AttemptStatus::Acquired && ! $attempt->matches($fingerprint)) {
            $this->conflict($request, $key, $scope, IdempotencyKeyConflict::REASON_MISMATCH, 422);

            throw IdempotencyException::reused($header);
        }

        if ($attempt->status === AttemptStatus::Completed) {
            PackageEvents::dispatch(IdempotentRequestReplayed::class, fn () => new IdempotentRequestReplayed(
                $request, $key, $scope, $attempt->response->status,
            ));

            return $this->replay($attempt->response, $config);
        }

        if ($attempt->status === AttemptStatus::InProgress) {
            $this->conflict($request, $key, $scope, IdempotencyKeyConflict::REASON_IN_PROGRESS, 409);

            throw IdempotencyException::inProgress($header, (int) ($config['retry_after'] ?? 1));
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $store->release($attempt);

            throw $e;
        }

        if ($this->shouldStore($response, $config)) {
            $store->complete($attempt, StoredResponse::fromResponse($response, $config['except_headers'] ?? []), $ttl);
        } else {
            $store->release($attempt);
        }

        return $response;
    }

    protected function conflict(Request $request, string $key, string $scope, string $reason, int $status): void
    {
        PackageEvents::dispatch(IdempotencyKeyConflict::class, fn () => new IdempotencyKeyConflict(
            $request, $key, $scope, $reason, $status,
        ));
    }

    /**
     * Statuses below 500 are stored, except release_statuses (auth, validation, rate limits:
     * nothing happened yet, the client may fix the request and retry with the same key).
     * Streamed and file responses cannot be stored; the key is released for them.
     *
     * @param  array<string, mixed>  $config
     */
    protected function shouldStore(Response $response, array $config): bool
    {
        $status = $response->getStatusCode();

        return $status < 500
            && ! in_array($status, $config['release_statuses'] ?? [], true)
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse
            && $response->getContent() !== false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function replay(StoredResponse $stored, array $config): Response
    {
        $response = $stored->toResponse();
        $response->headers->set($config['replay_header'] ?? 'Idempotent-Replayed', 'true');

        return $response;
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $config
     * @return array{0: bool, 1: int}
     */
    protected function options(array $options, array $config): array
    {
        $required = (bool) ($config['required'] ?? false);
        $ttl = (int) ($config['ttl'] ?? 86400);

        foreach ($options as $option) {
            match (true) {
                $option === 'required' => $required = true,
                $option === 'optional' => $required = false,
                str_starts_with($option, 'ttl=') => $ttl = (int) substr($option, 4),
                default => throw new \InvalidArgumentException("Unknown idempotent middleware option [{$option}]."),
            };
        }

        return [$required, max(1, $ttl)];
    }
}
