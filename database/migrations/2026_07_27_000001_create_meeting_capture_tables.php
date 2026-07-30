<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_capture_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_bot_id')->nullable()->unique();
            $table->string('platform')->nullable();
            $table->string('status');
            $table->char('meeting_url_hash', 64)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('meeting_capture_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider_participant_id');
            $table->string('display_name')->nullable();
            $table->text('email')->nullable();
            $table->char('email_hash', 64)->nullable();
            $table->boolean('is_host')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->string('sales_role')->default('unknown');
            $table->timestamps();

            $table->unique(['meeting_capture_session_id', 'provider_participant_id']);
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('meeting_capture_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_webhook_id')->unique();
            $table->string('provider_event_type');
            $table->string('provider_event_id')->nullable();
            $table->foreignId('meeting_participant_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->timestamp('provider_occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['meeting_capture_session_id', 'id']);
            $table->index(['meeting_capture_session_id', 'provider_event_type']);
        });

        Schema::create('meeting_analysis_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('meeting_capture_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_transcript_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('status')->default('pending');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('leased_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['meeting_capture_session_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_analysis_deliveries');
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meeting_capture_sessions');
    }
};
