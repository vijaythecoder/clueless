<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->json('openai_session_ids')->nullable()->after('template_used');
            $table->json('metadata')->nullable()->after('user_notes');
        });

        Schema::table('conversation_transcripts', function (Blueprint $table) {
            $table->string('source_stream')->nullable()->after('speaker');
            $table->string('openai_item_id')->nullable()->after('spoken_at');
            $table->string('status')->default('final')->after('openai_item_id');
            $table->json('metadata')->nullable()->after('system_category');

            $table->index(['session_id', 'source_stream']);
            $table->index(['session_id', 'openai_item_id']);
        });

        Schema::table('conversation_insights', function (Blueprint $table) {
            $table->string('tool_call_id')->nullable()->after('insight_type');
            $table->string('card_type')->nullable()->after('tool_call_id');
            $table->string('approval_status')->nullable()->after('card_type');
            $table->json('metadata')->nullable()->after('data');

            $table->index(['session_id', 'card_type']);
            $table->index(['session_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_insights', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'card_type']);
            $table->dropIndex(['session_id', 'approval_status']);
            $table->dropColumn(['tool_call_id', 'card_type', 'approval_status', 'metadata']);
        });

        Schema::table('conversation_transcripts', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'source_stream']);
            $table->dropIndex(['session_id', 'openai_item_id']);
            $table->dropColumn(['source_stream', 'openai_item_id', 'status', 'metadata']);
        });

        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->dropColumn(['openai_session_ids', 'metadata']);
        });
    }
};
