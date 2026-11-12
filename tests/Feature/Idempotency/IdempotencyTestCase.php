<?php

namespace Toshkq93\Outbox\Tests\Feature\Idempotency;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Toshkq93\Outbox\Facades\Idempotency;
use Toshkq93\Outbox\Http\Middleware\Idempotent;
use Toshkq93\Outbox\Idempotency\AttemptStatus;
use Toshkq93\Outbox\Idempotency\StoredResponse;
use Toshkq93\Outbox\Tests\TestCase;

/**
 * The middleware contract, run once per driver.
 */
abstract class IdempotencyTestCase extends TestCase
{
    use RefreshDatabase;

    public static int $runs = 0;

    abstract protected function driver(): string;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('outbox.idempotency.driver', $this->driver());
        $app['config']->set('outbox.idempotency.cache_store', 'array');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('idempotent')->group(function ($router) {
            $router->post('/orders', function (Request $request) {
                self::$runs++;

                return response()->json(['order' => self::$runs, 'input' => $request->all()], 201)
                    ->header('X-Order', (string) self::$runs)
                    ->cookie('flavour', 'oatmeal');
            });
            $router->get('/orders', fn () => ['order' => ++self::$runs]);
            $router->put('/orders/{id}', fn ($id) => ['updated' => $id, 'run' => ++self::$runs]);
            $router->post('/fail', function () {
                self::$runs++;

                throw new \RuntimeException('database is down');
            });
            $router->post('/server-error', fn () => response(['run' => ++self::$runs], 503));
            $router->post('/validate', function (Request $request) {
                self::$runs++;
                $request->validate(['name' => 'required']);

                return ['run' => self::$runs];
            });
            $router->post('/missing', function () {
                self::$runs++;
                abort(404);
            });
            $router->post('/stream', function () {
                self::$runs++;

                return response()->stream(function () {
                    echo 'streamed';
                });
            });
            $router->post('/upload', fn (Request $request) => ['run' => ++self::$runs, 'name' => $request->file('doc')->getClientOriginalName()]);
        });

        $router->post('/required', fn () => ['run' => ++self::$runs])->middleware('idempotent:required');
        $router->post('/short', fn () => ['run' => ++self::$runs])->middleware(Idempotent::using(required: false, ttl: 60));
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$runs = 0;
        Date::setTestNow('2026-11-11 10:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        Idempotency::resolveScopeUsing(null);
        Idempotency::fingerprintUsing(null);

        parent::tearDown();
    }

    private function send(string $uri, array $data = [], ?string $key = 'key-1', array $headers = []): TestResponse
    {
        return $this->postJson($uri, $data, $key === null ? $headers : ['Idempotency-Key' => $key] + $headers);
    }

    public function test_a_repeated_request_replays_the_stored_response(): void
    {
        $first = $this->send('/orders', ['sku' => 'A']);
        $second = $this->send('/orders', ['sku' => 'A']);

        $first->assertCreated()->assertJson(['order' => 1])->assertHeaderMissing('Idempotent-Replayed')->assertCookie('flavour');
        $second->assertCreated()
            ->assertExactJson(['order' => 1, 'input' => ['sku' => 'A']])
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('X-Order', '1')
            ->assertHeader('Content-Type', 'application/json')
            ->assertCookieMissing('flavour');
        $this->assertSame(1, self::$runs);
    }

    public function test_json_key_order_and_whitespace_do_not_change_the_fingerprint(): void
    {
        $this->call('POST', '/orders', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k'], '{"a":1,"b":{"y":2,"x":1}}')->assertCreated();
        $this->call('POST', '/orders', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k'], '{ "b": {"x":1, "y":2}, "a": 1 }')
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, self::$runs);
    }

    public function test_the_same_key_with_another_request_is_rejected(): void
    {
        $this->send('/orders', ['sku' => 'A'])->assertCreated();

        $this->send('/orders', ['sku' => 'B'])->assertStatus(422)->assertJson(['message' => 'This Idempotency-Key was already used with a different request.']);
        $this->send('/orders?draft=1', ['sku' => 'A'])->assertStatus(422);
        $this->putJson('/orders/5', ['sku' => 'A'], ['Idempotency-Key' => 'key-1'])->assertStatus(422);
        $this->assertSame(1, self::$runs);
    }

    public function test_requests_without_a_key_or_with_other_methods_pass_through(): void
    {
        $this->send('/orders', key: null)->assertCreated();
        $this->send('/orders', key: null)->assertCreated();
        $this->getJson('/orders', ['Idempotency-Key' => 'key-1'])->assertOk();
        $this->getJson('/orders', ['Idempotency-Key' => 'key-1'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(4, self::$runs);
    }

    public function test_a_key_can_be_required(): void
    {
        $this->send('/required', key: null)->assertStatus(400)->assertJson(['message' => 'The Idempotency-Key header is required for this request.']);

        config(['outbox.idempotency.required' => true]);
        $this->send('/orders', key: null)->assertStatus(400);
        $this->send('/short', key: null)->assertOk(); // the route says optional

        $this->send('/required')->assertOk();
        $this->assertSame(2, self::$runs);
    }

    public function test_invalid_keys_are_rejected(): void
    {
        $this->send('/orders', key: str_repeat('a', 256))->assertStatus(400)->assertJson(['message' => 'The Idempotency-Key header is invalid.']);
        $this->send('/orders', key: 'with space')->assertStatus(400);

        config(['outbox.idempotency.key_pattern' => '/\A[0-9a-f-]{36}\z/']);
        $this->send('/orders', key: 'not-a-uuid')->assertStatus(400);
        $this->send('/orders', key: '6f1c2f9e-0c1b-4d7e-9a43-2b6f2c1d9e10')->assertCreated();

        config(['outbox.idempotency.key_pattern' => '/.+/']);
        $this->send('/orders', key: str_repeat('a', 256))->assertStatus(400);

        $this->assertSame(1, self::$runs);
    }

    public function test_exceptions_and_server_errors_release_the_key(): void
    {
        $this->withoutExceptionHandling([\RuntimeException::class]);

        $this->send('/fail')->assertStatus(500);
        $this->send('/fail')->assertStatus(500);
        $this->send('/server-error', key: 'key-2')->assertStatus(503);
        $this->send('/server-error', key: 'key-2')->assertStatus(503)->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(4, self::$runs);
    }

    public function test_validation_errors_are_not_stored_so_the_key_can_be_retried_with_a_fixed_body(): void
    {
        $this->send('/validate', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->send('/validate', ['name' => 'Ann'])->assertOk()->assertJson(['run' => 2]);
        $this->send('/validate', ['name' => 'Ann'])->assertOk()->assertJson(['run' => 2])->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_other_client_errors_are_stored(): void
    {
        $this->send('/missing')->assertNotFound();
        $this->send('/missing')->assertNotFound()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, self::$runs);
    }

    public function test_streamed_responses_are_not_stored(): void
    {
        $this->send('/stream')->assertOk();
        $this->send('/stream')->assertOk()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, self::$runs);
    }

    public function test_a_running_request_makes_repeats_wait_with_409(): void
    {
        $request = Request::create('/orders', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json'], content: '{"sku":"A"}');
        $attempt = Idempotency::store()->acquire(Idempotency::scope($request), 'key-1', Idempotency::fingerprint($request), 60, 3600);
        $this->assertSame(AttemptStatus::Acquired, $attempt->status);

        $this->send('/orders', ['sku' => 'A'])->assertStatus(409)->assertHeader('Retry-After', '1');
        $this->send('/orders', ['sku' => 'B'])->assertStatus(422);
        $this->assertSame(0, self::$runs);

        Idempotency::store()->release($attempt);
        $this->send('/orders', ['sku' => 'A'])->assertCreated();
    }

    public function test_a_crashed_request_frees_the_key_when_its_lock_runs_out(): void
    {
        $request = Request::create('/orders', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json'], content: '{"sku":"A"}');
        $crashed = Idempotency::store()->acquire(Idempotency::scope($request), 'key-1', Idempotency::fingerprint($request), 60, 3600);

        $this->travel(61)->seconds();
        $this->send('/orders', ['sku' => 'A'])->assertCreated();

        // The crashed request finishing late must not overwrite or drop the new result.
        Idempotency::store()->complete($crashed, new StoredResponse(200, [], 'late'), 3600);
        Idempotency::store()->release($crashed);
        $this->send('/orders', ['sku' => 'A'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJson(['order' => 1]);
    }

    public function test_stored_responses_expire_after_the_ttl(): void
    {
        $this->send('/orders')->assertCreated();
        $this->send('/short', key: 'key-2')->assertOk();

        $this->travel(61)->seconds();
        $this->send('/short', key: 'key-2')->assertOk()->assertJson(['run' => 3])->assertHeaderMissing('Idempotent-Replayed');
        $this->send('/orders')->assertHeader('Idempotent-Replayed', 'true');

        $this->travel(1)->days();
        $this->send('/orders')->assertCreated()->assertJson(['order' => 4]);
    }

    public function test_keys_are_scoped_to_the_user_or_guest_address(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]))->send('/orders')->assertJson(['order' => 1]);
        $this->actingAs(new GenericUser(['id' => 2]))->send('/orders')->assertJson(['order' => 2]);
        $this->actingAs(new GenericUser(['id' => 1]))->send('/orders')->assertJson(['order' => 1])->assertHeader('Idempotent-Replayed', 'true');

        app('auth')->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->send('/orders')->assertJson(['order' => 3]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->send('/orders')->assertJson(['order' => 4]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->send('/orders')->assertJson(['order' => 3]);
    }

    public function test_scope_and_fingerprint_can_be_customised(): void
    {
        Idempotency::resolveScopeUsing(fn (Request $request) => 'tenant:'.$request->header('X-Tenant'));
        Idempotency::fingerprintUsing(fn (Request $request) => $request->path()); // body ignored

        $this->send('/orders', ['sku' => 'A'], headers: ['X-Tenant' => 'acme'])->assertJson(['order' => 1]);
        $this->send('/orders', ['sku' => 'B'], headers: ['X-Tenant' => 'acme'])->assertJson(['order' => 1])->assertHeader('Idempotent-Replayed', 'true');
        $this->send('/orders', ['sku' => 'A'], headers: ['X-Tenant' => 'globex'])->assertJson(['order' => 2]);
    }

    public function test_configured_headers_are_part_of_the_fingerprint(): void
    {
        config(['outbox.idempotency.fingerprint_headers' => ['X-Api-Version']]);

        $this->send('/orders', headers: ['X-Api-Version' => '1'])->assertCreated();
        $this->send('/orders', headers: ['X-Api-Version' => '2'])->assertStatus(422);
    }

    public function test_uploaded_files_are_compared_by_content(): void
    {
        $upload = fn (string $content) => $this->call('POST', '/upload', ['title' => 'x'], [], [
            'doc' => UploadedFile::fake()->createWithContent('a.txt', $content),
        ], ['HTTP_IDEMPOTENCY_KEY' => 'file-1', 'HTTP_ACCEPT' => 'application/json']);

        $upload('one')->assertOk();
        $upload('one')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $upload('two')->assertStatus(422);
    }

    public function test_a_disabled_middleware_passes_through(): void
    {
        config(['outbox.idempotency.enabled' => false]);

        $this->send('/orders')->assertCreated();
        $this->send('/orders')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, self::$runs);
    }

    public function test_binary_bodies_survive_the_round_trip(): void
    {
        $this->app['router']->post('/binary', fn () => response("\x00\xFF\x10png", 201, ['Content-Type' => 'image/png']))->middleware('idempotent');

        $this->send('/binary')->assertCreated();
        $replayed = $this->send('/binary')->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame("\x00\xFF\x10png", $replayed->getContent());
        $this->assertSame('image/png', $replayed->headers->get('Content-Type'));
    }

    public function test_unknown_middleware_options_fail_loudly(): void
    {
        $this->app['router']->post('/typo', fn () => 'ok')->middleware('idempotent:requird');
        $this->withoutExceptionHandling();

        $this->expectException(\InvalidArgumentException::class);
        $this->send('/typo');
    }
}
