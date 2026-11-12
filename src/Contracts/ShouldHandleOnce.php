<?php

namespace Toshkq93\Outbox\Contracts;

/**
 * Marker for listeners that must handle each outbox message at most once (inbox deduplication).
 *
 * Synchronous listeners: when the event they listen to is being delivered from the outbox, the
 * listener runs inside Inbox::once() with the delivered message id and the listener as consumer;
 * a redelivered message is skipped. Events dispatched any other way reach the listener as usual.
 *
 * Queued listeners (ShouldQueue) run in their own job, so they deduplicate through the job
 * middleware instead: return [new DeduplicateMessage] from the listener's middleware().
 *
 * The consumer name defaults to the listener class ("Class@method" for a method other than
 * handle / __invoke). Define `public static function inboxConsumer(): string` to keep it stable
 * across class renames.
 */
interface ShouldHandleOnce {}
