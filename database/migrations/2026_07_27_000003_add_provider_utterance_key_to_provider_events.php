<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_events', function (Blueprint $table) {
            $table->string('provider_utterance_key')->nullable()->after('provider_event_id');
            $table->index(
                ['meeting_capture_session_id', 'provider_event_type', 'provider_utterance_key'],
                'provider_events_capture_type_utterance_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('provider_events', function (Blueprint $table) {
            $table->dropIndex('provider_events_capture_type_utterance_index');
            $table->dropColumn('provider_utterance_key');
        });
    }
};
