<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Side effects of inbox tests; "entry" is unique to provoke the handler's own constraint errors.
        Schema::create('ledger', function (Blueprint $table) {
            $table->id();
            $table->string('entry')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger');
    }
};
