<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bus_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->unique();
            $table->string('subject');
            $table->string('session_id');
            $table->string('agent_type')->nullable();
            $table->string('model')->nullable();
            $table->string('repo')->nullable();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->index(['type', 'occurred_at']);
            $table->index(['model', 'occurred_at']);
            $table->index(['session_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_events');
    }
};
