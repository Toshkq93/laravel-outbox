<?php

namespace Toshkq93\Outbox\Idempotency;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Toshkq93\Outbox\Contracts\IdempotencyStore;

/**
 * Settings and helpers of the idempotency middleware: store, scope and fingerprint.
 */
class Idempotency
{
    /** @var (Closure(Request): string)|null */
    protected ?Closure $scopeResolver = null;

    /** @var (Closure(Request): string)|null */
    protected ?Closure $fingerprintResolver = null;

    public function __construct(
        protected Config $config,
        protected IdempotencyStoreManager $stores,
    ) {}

    public function store(?string $driver = null): IdempotencyStore
    {
        return $this->stores->driver($driver);
    }

    /**
     * Whose key it is. Default: "user:{id}" for an authenticated user, else "ip:{address}".
     *
     * @param  (Closure(Request): string)|null  $resolver
     */
    public function resolveScopeUsing(?Closure $resolver): void
    {
        $this->scopeResolver = $resolver;
    }

    /**
     * What makes two requests "the same". The default covers method, path, query, body
     * (JSON canonicalized: key order does not matter; uploaded files by content) and the
     * headers in outbox.idempotency.fingerprint_headers.
     *
     * @param  (Closure(Request): string)|null  $resolver
     */
    public function fingerprintUsing(?Closure $resolver): void
    {
        $this->fingerprintResolver = $resolver;
    }

    public function scope(Request $request): string
    {
        $scope = $this->scopeResolver
            ? ($this->scopeResolver)($request)
            : $this->defaultScope($request);

        // Fits the 191-character column whatever a custom resolver returns.
        return strlen($scope) > 191 ? 'sha256:'.hash('sha256', $scope) : $scope;
    }

    public function fingerprint(Request $request): string
    {
        if ($this->fingerprintResolver) {
            return hash('sha256', ($this->fingerprintResolver)($request));
        }

        $query = $request->query->all();
        $this->sortRecursive($query);

        $headers = [];
        foreach ($this->config->get('outbox.idempotency.fingerprint_headers', []) as $name) {
            $headers[strtolower($name)] = $request->headers->all($name);
        }
        ksort($headers);

        return hash('sha256', json_encode([
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => $query,
            'body' => $this->body($request),
            'headers' => $headers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    protected function defaultScope(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'user:'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();
    }

    protected function body(Request $request): mixed
    {
        if ($request->isJson()) {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($data)) {
                    $this->sortRecursive($data);
                }

                return ['json' => $data];
            }
        }

        $files = $request->allFiles();

        if ($request->request->count() > 0 || $files !== []) {
            $input = $request->request->all();
            $this->sortRecursive($input);

            return ['form' => $input, 'files' => $this->files($files)];
        }

        return ['raw' => hash('sha256', $request->getContent())];
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    protected function files(array $files): array
    {
        ksort($files);

        return array_map(fn ($file) => is_array($file)
            ? $this->files($file)
            : [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files);
    }

    /**
     * Sort maps by key at every level (lists keep their order).
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function sortRecursive(array &$data): void
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }
}
