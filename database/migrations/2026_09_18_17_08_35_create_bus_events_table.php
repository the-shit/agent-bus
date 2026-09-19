<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->nullable()->unique();
            $table->string('correlation_id')->nullable()->index();
            $table->string('causation_id')->nullable();
            $table->unsignedBigInteger('seq')->nullable()->unique();
            $table->string('subject')->index();
            $table->string('session_id')->nullable()->index();
            $table->string('agent_type')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('repo')->nullable()->index();
            $table->string('type')->index();
            $table->json('payload');
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_events');
    }
};
