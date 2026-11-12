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
        Schema::create(config('outbox.idempotency.table', 'idempotency_keys'), function (Blueprint $table) {
            $table->id();
            // Whose key it is (user / guest IP / custom): clients cannot see each other's responses.
            $table->string('scope', 191);
            $table->string('key');
            // sha256 of method, path, query and body: the same key with another request is a 422.
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            // Random token of the request holding the key while it runs.
            $table->string('owner', 64)->nullable();
            $table->timestamp('locked_until', 6)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_headers')->nullable();
            // base64: responses may be binary.
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('expires_at', 6);

            $table->unique(['scope', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('outbox.idempotency.table', 'idempotency_keys'));
    }
};
