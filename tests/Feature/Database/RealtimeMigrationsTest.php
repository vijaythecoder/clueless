<?php

use Illuminate\Support\Facades\Schema;

test('secure settings migration creates a unique encrypted-value store', function () {
    expect(Schema::hasTable('secure_settings'))->toBeTrue()
        ->and(Schema::hasColumns('secure_settings', ['id', 'key', 'value', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasIndex('secure_settings', ['key'], 'unique'))->toBeTrue();
});

test('realtime persistence identifiers have unique indexes', function () {
    expect(Schema::hasIndex(
        'conversation_transcripts',
        'conversation_transcripts_session_stream_item_unique',
        'unique',
    ))->toBeTrue()
        ->and(Schema::hasIndex(
            'conversation_transcripts',
            'conversation_transcripts_session_order_unique',
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'conversation_insights',
            'conversation_insights_session_tool_call_unique',
            'unique',
        ))->toBeTrue();
});
