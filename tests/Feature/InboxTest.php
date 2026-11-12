<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Toshkq93\Outbox\Facades\Inbox;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\Models\InboxMessage;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\NotifyAccounting;
use Toshkq93\Outbox\Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): array
    {
        return DB::table('ledger')->orderBy('id')->pluck('entry')->all();
    }

    public function test_a_message_is_handled_once_per_consumer(): void
    {
        $message = new Message('m-1', 'invoice.issued', []);
        $write = fn (string $entry) => fn () => DB::table('ledger')->insert(['entry' => $entry]);

        $this->assertTrue(Inbox::once($message, 'billing', $write('first')));
        $this->assertFalse(Inbox::once($message, 'billing', $write('second')));
        $this->assertFalse(Inbox::once('m-1', 'billing', $write('third')));
        $this->assertTrue(Inbox::once($message, 'reporting', $write('other consumer')));
        $this->assertTrue(Inbox::once('m-2', 'billing', $write('other message')));

        $this->assertSame(['first', 'other consumer', 'other message'], $this->ledger());

        $key = InboxMessage::query()->where('message_id', 'm-1')->where('consumer', 'billing')->sole();
        $this->assertSame('invoice.issued', $key->type);
        $this->assertNotNull($key->processed_at);
        $this->assertNull(InboxMessage::query()->where('message_id', 'm-2')->value('type'));
    }

    public function test_a_failing_handler_rolls_back_its_writes_and_the_key(): void
    {
        try {
            Inbox::once('m-1', 'billing', function () {
                DB::table('ledger')->insert(['entry' => 'half done']);

                throw new RuntimeException('boom');
            });
            $this->fail('the exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->ledger());
        $this->assertFalse(Inbox::processed('m-1', 'billing'));

        $this->assertTrue(Inbox::once('m-1', 'billing', fn () => DB::table('ledger')->insert(['entry' => 'retried'])));
        $this->assertSame(['retried'], $this->ledger());
    }

    public function test_unique_violations_of_the_handler_itself_are_not_mistaken_for_duplicates(): void
    {
        DB::table('ledger')->insert(['entry' => 'taken']);

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            Inbox::once('m-1', 'billing', fn () => DB::table('ledger')->insert(['entry' => 'taken']));
        } finally {
            $this->assertFalse(Inbox::processed('m-1', 'billing'));
        }
    }

    public function test_inside_an_outer_transaction_a_duplicate_leaves_the_transaction_usable(): void
    {
        Inbox::once('m-1', 'billing', fn () => null);

        DB::transaction(function () {
            DB::table('ledger')->insert(['entry' => 'before']);

            // On PostgreSQL a failed statement aborts the transaction; once() runs in a savepoint.
            $this->assertFalse(Inbox::once('m-1', 'billing', fn () => DB::table('ledger')->insert(['entry' => 'dup'])));

            DB::table('ledger')->insert(['entry' => 'after']);
        });

        $this->assertSame(['before', 'after'], $this->ledger());
    }

    public function test_the_key_commits_with_the_outer_transaction(): void
    {
        try {
            DB::transaction(function () {
                Inbox::once('m-1', 'billing', fn () => DB::table('ledger')->insert(['entry' => 'inner']));

                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(Inbox::processed('m-1', 'billing'));
        $this->assertSame([], $this->ledger());
    }

    public function test_processed_and_forget(): void
    {
        Inbox::once('m-1', 'billing', fn () => null);

        $this->assertTrue(Inbox::processed('m-1', 'billing'));
        $this->assertTrue(Inbox::processed(new Message('m-1', 't', []), 'billing'));
        $this->assertFalse(Inbox::processed('m-1', 'reporting'));

        $this->assertTrue(Inbox::forget('m-1', 'billing'));
        $this->assertFalse(Inbox::forget('m-1', 'billing'));
        $this->assertTrue(Inbox::once('m-1', 'billing', fn () => null));
    }

    public function test_a_disabled_inbox_always_runs_the_handler(): void
    {
        config(['outbox.inbox.enabled' => false]);
        $runs = 0;

        $this->assertTrue(Inbox::once('m-1', 'billing', function () use (&$runs) {
            $runs++;
        }));
        $this->assertTrue(Inbox::once('m-1', 'billing', function () use (&$runs) {
            $runs++;
        }));

        $this->assertSame(2, $runs);
        $this->assertSame(0, InboxMessage::query()->count());
    }

    public function test_the_consumer_name_of_a_listener(): void
    {
        $this->assertSame(self::class, Inbox::consumerFor(self::class));
        $this->assertSame(self::class, Inbox::consumerFor(self::class, '__invoke'));
        $this->assertSame(self::class.'@onIssued', Inbox::consumerFor(self::class, 'onIssued'));
        $this->assertSame('accounting.notify', Inbox::consumerFor(NotifyAccounting::class, 'onIssued'));
    }

    public function test_the_prune_command_deletes_old_keys(): void
    {
        Date::setTestNow('2026-11-01 10:00:00');
        Inbox::once('old', 'billing', fn () => null);
        Inbox::once('old', 'reporting', fn () => null);
        Date::setTestNow('2026-11-11 09:00:00');
        Inbox::once('recent', 'billing', fn () => null);
        Date::setTestNow('2026-11-11 10:00:00');

        $this->artisan('inbox:prune', ['--hours' => 24 * 5, '--chunk' => 1])
            ->expectsOutputToContain('2 inbox key(s) pruned.')
            ->assertSuccessful();
        $this->assertSame(['recent'], InboxMessage::query()->pluck('message_id')->all());

        $this->artisan('inbox:prune')->expectsOutputToContain('0 inbox key(s) pruned.');
        config(['outbox.inbox.prune_after_hours' => 0]);
        $this->artisan('inbox:prune')->expectsOutputToContain('1 inbox key(s) pruned.');

        Date::setTestNow();
    }
}
