<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversation_transcripts')
            ->whereNotNull('openai_item_id')
            ->whereNull('source_stream')
            ->update(['source_stream' => DB::raw('speaker')]);

        $this->deduplicateTranscripts();
        $this->deduplicateInsights();
        $this->normalizeTranscriptOrder();

        Schema::table('conversation_transcripts', function (Blueprint $table) {
            $table->unique(
                ['session_id', 'source_stream', 'openai_item_id'],
                'conversation_transcripts_session_stream_item_unique',
            );
            $table->unique(
                ['session_id', 'order_index'],
                'conversation_transcripts_session_order_unique',
            );
        });

        Schema::table('conversation_insights', function (Blueprint $table) {
            $table->unique(
                ['session_id', 'tool_call_id'],
                'conversation_insights_session_tool_call_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('conversation_insights', function (Blueprint $table) {
            $table->dropUnique('conversation_insights_session_tool_call_unique');
        });

        Schema::table('conversation_transcripts', function (Blueprint $table) {
            $table->dropUnique('conversation_transcripts_session_stream_item_unique');
            $table->dropUnique('conversation_transcripts_session_order_unique');
        });
    }

    private function deduplicateTranscripts(): void
    {
        $duplicates = DB::table('conversation_transcripts')
            ->select([
                'session_id',
                'source_stream',
                'openai_item_id',
                DB::raw('MAX(id) as keep_id'),
            ])
            ->whereNotNull('openai_item_id')
            ->groupBy('session_id', 'source_stream', 'openai_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('conversation_transcripts')
                ->where('session_id', $duplicate->session_id)
                ->where('source_stream', $duplicate->source_stream)
                ->where('openai_item_id', $duplicate->openai_item_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }
    }

    private function deduplicateInsights(): void
    {
        $duplicates = DB::table('conversation_insights')
            ->select([
                'session_id',
                'tool_call_id',
                DB::raw('MAX(id) as keep_id'),
            ])
            ->whereNotNull('tool_call_id')
            ->groupBy('session_id', 'tool_call_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('conversation_insights')
                ->where('session_id', $duplicate->session_id)
                ->where('tool_call_id', $duplicate->tool_call_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }
    }

    private function normalizeTranscriptOrder(): void
    {
        $sessionIds = DB::table('conversation_transcripts')
            ->distinct()
            ->pluck('session_id');

        foreach ($sessionIds as $sessionId) {
            $ids = DB::table('conversation_transcripts')
                ->where('session_id', $sessionId)
                ->orderBy('order_index')
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids as $index => $id) {
                DB::table('conversation_transcripts')
                    ->where('id', $id)
                    ->update(['order_index' => $index + 1]);
            }
        }
    }
};
