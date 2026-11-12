<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;
use Toshkq93\Outbox\Tests\Fixtures\RecordingPublisher;
use Toshkq93\Outbox\Tests\TestCase;

class CommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingPublisher::reset();
        Date::setTestNow('2026-11-11 10:00:00');
        config([
            'outbox.outbox.publisher' => 'recording',
            'outbox.outbox.publishers.recording' => ['driver' => RecordingPublisher::class],
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function record(string $type, array $attributes = []): OutboxMessage
    {
        $message = DB::transaction(fn () => Outbox::recordRaw($type, []));

        if ($attributes !== []) {
            $message->forceFill($attributes)->save();
        }

        return $message;
    }

    public function test_relay_once(): void
    {
        $this->record('a');
        $this->record('b');

        $this->artisan('outbox:relay', ['--once' => true])
            ->expectsOutputToContain('Claimed 2: 2 published')
            ->assertSuccessful();

        $this->assertSame(['a', 'b'], RecordingPublisher::types());
    }

    public function test_relay_reports_failures_verbosely(): void
    {
        RecordingPublisher::$failing['a'] = true;
        $message = $this->record('a');

        $exit = Artisan::call('outbox:relay', ['--once' => true, '-v' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Claimed 1: 0 published, 1 failed', $output);
        $this->assertStringContainsString($message->message_id.' RuntimeException: Broker rejected a', $output);
    }

    public function test_relay_loops_until_max_messages(): void
    {
        Sleep::fake();
        foreach (range(1, 5) as $i) {
            $this->record("m{$i}");
        }

        $this->artisan('outbox:relay', ['--batch' => 2, '--max-messages' => 3])
            ->expectsOutputToContain('Stopping relay: max messages reached')
            ->assertSuccessful();

        $this->assertCount(4, RecordingPublisher::$published); // stops after the batch that crosses the limit
    }

    public function test_relay_stop_when_empty_and_sleep(): void
    {
        Sleep::fake();
        $this->record('a');

        $this->artisan('outbox:relay', ['--stop-when-empty' => true, '--batch' => 1])->assertSuccessful();

        $this->assertSame(['a'], RecordingPublisher::types());
        Sleep::assertSleptTimes(0);
    }

    public function test_retry_requires_a_selection(): void
    {
        $this->artisan('outbox:retry')->expectsOutputToContain('Pass message ids, --type or --all')->assertFailed();
    }

    public function test_retry_by_id_type_and_all(): void
    {
        $dead = ['status' => OutboxStatus::Dead, 'attempts' => 10, 'last_error' => 'boom', 'available_at' => Date::now()->addDay()];
        $a = $this->record('a', $dead);
        $b = $this->record('b', $dead);
        $c = $this->record('c', $dead);
        $d = $this->record('d', $dead);
        $pending = $this->record('pending', ['attempts' => 2]);

        $this->artisan('outbox:retry', ['id' => [$a->message_id, (string) $b->id]])
            ->expectsOutputToContain('2 dead message(s) queued')
            ->assertSuccessful();

        $a->refresh();
        $this->assertSame(OutboxStatus::Pending, $a->status);
        $this->assertSame(0, $a->attempts);
        $this->assertTrue($a->available_at->equalTo(Date::now()));
        $this->assertSame(OutboxStatus::Pending, $b->refresh()->status);
        $this->assertSame(OutboxStatus::Dead, $c->refresh()->status);

        $this->artisan('outbox:retry', ['--type' => 'c'])->expectsOutputToContain('1 dead message(s)');
        $this->artisan('outbox:retry', ['--all' => true])->expectsOutputToContain('1 dead message(s)');

        $this->assertSame(OutboxStatus::Pending, $d->refresh()->status);
        $this->assertSame(2, $pending->refresh()->attempts, 'pending messages are left alone');
    }

    public function test_prune_deletes_old_published_messages_only(): void
    {
        $old = $this->record('old', ['status' => OutboxStatus::Published, 'published_at' => Date::now()->subHours(25)]);
        $recent = $this->record('recent', ['status' => OutboxStatus::Published, 'published_at' => Date::now()->subHours(23)]);
        $deadOld = $this->record('dead', ['status' => OutboxStatus::Dead, 'created_at' => Date::now()->subYear()]);
        $pending = $this->record('pending');

        $this->artisan('outbox:prune', ['--hours' => 24, '--chunk' => 1])
            ->expectsOutputToContain('1 published message(s) pruned')
            ->assertSuccessful();

        $this->assertNull(OutboxMessage::query()->find($old->id));
        $this->assertEqualsCanonicalizing([$recent->id, $deadOld->id, $pending->id], OutboxMessage::query()->pluck('id')->all());
    }

    public function test_status_json(): void
    {
        $this->record('due');
        $this->record('later', ['available_at' => Date::now()->addMinute(), 'attempts' => 1]);
        $this->record('leased', ['locked_until' => Date::now()->addMinute(), 'locked_by' => 'r']);
        $this->record('dead', ['status' => OutboxStatus::Dead]);
        $this->record('published', ['status' => OutboxStatus::Published, 'published_at' => Date::now()]);
        OutboxMessage::query()->where('type', 'due')->update(['created_at' => Date::now()->subSeconds(42)->format('Y-m-d H:i:s.u')]);

        Artisan::call('outbox:status', ['--json' => true]);

        $this->assertSame([
            'pending' => 3,
            'due' => 1,
            'leased' => 1,
            'retrying' => 1,
            'dead' => 1,
            'published' => 1,
            'lag_seconds' => 42,
        ], json_decode(Artisan::output(), true));
    }

    public function test_status_table(): void
    {
        $this->artisan('outbox:status')->expectsOutputToContain('Pending')->assertSuccessful();
    }
}
