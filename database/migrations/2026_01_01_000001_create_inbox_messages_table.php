<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('outbox.connection');
    }

    public function up(): void
    {
        Schema::create(config('outbox.inbox.table', 'inbox_messages'), function (Blueprint $table) {
            $table->id();
            // A string, not a uuid: messages from other systems may use any id format.
            $table->string('message_id');
            // Who handled the message (listener / job class or a custom name): one message is
            // handled once per consumer, and different consumers do not block each other.
            $table->string('consumer');
            $table->string('type')->nullable();
            $table->timestamp('processed_at', 6);

            // The deduplication itself: a second insert of the same pair fails (or waits for the
            // first transaction to finish), so a message cannot be handled twice by one consumer.
            $table->unique(['message_id', 'consumer']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('outbox.inbox.table', 'inbox_messages'));
    }
};
