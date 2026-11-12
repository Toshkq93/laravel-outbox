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
        Schema::create(config('outbox.outbox.table', 'outbox_messages'), function (Blueprint $table) {
            // Auto-increment id gives a strict commit-independent order for the relay;
            // message_id is the stable identity consumers deduplicate on.
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('type');
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable();
            $table->string('publisher')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at', 6);
            $table->timestamp('locked_until', 6)->nullable();
            $table->string('locked_by')->nullable();
            $table->timestamp('published_at', 6)->nullable();
            $table->timestamp('created_at', 6);

            $table->index(['status', 'available_at', 'id']);
            $table->index(['aggregate_type', 'aggregate_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('outbox.outbox.table', 'outbox_messages'));
    }
};
