<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_transcripts', function (Blueprint $table) {
            $table->string('speaker')->change();
            $table->foreignUuid('meeting_capture_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_participant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('provider_item_id')->nullable();
            $table->unsignedBigInteger('started_offset_ms')->nullable();
            $table->unsignedBigInteger('ended_offset_ms')->nullable();
            $table->unique(
                ['session_id', 'provider', 'provider_item_id'],
                'conversation_transcripts_session_provider_item_unique',
            );
        });

        Schema::table('conversation_insights', function (Blueprint $table) {
            $table->string('semantic_key')->nullable();
            $table->unique(
                ['session_id', 'semantic_key'],
                'conversation_insights_session_semantic_key_unique',
            );
        });
    }

    public function down(): void
    {
        DB::connection($this->getConnection())
            ->table('conversation_transcripts')
            ->whereIn('speaker', ['unknown', 'bot'])
            ->update(['speaker' => 'system']);

        Schema::table('conversation_insights', function (Blueprint $table) {
            $table->dropUnique('conversation_insights_session_semantic_key_unique');
            $table->dropColumn('semantic_key');
        });

        Schema::table('conversation_transcripts', function (Blueprint $table) {
            $table->dropUnique('conversation_transcripts_session_provider_item_unique');
            $table->dropForeign(['meeting_capture_session_id']);
            $table->dropForeign(['meeting_participant_id']);
            $table->dropColumn([
                'meeting_capture_session_id',
                'meeting_participant_id',
                'provider',
                'provider_item_id',
                'started_offset_ms',
                'ended_offset_ms',
            ]);
            $table->enum('speaker', ['salesperson', 'customer', 'system'])->change();
        });
    }
};
