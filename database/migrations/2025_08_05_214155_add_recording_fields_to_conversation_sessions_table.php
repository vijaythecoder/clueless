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
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->boolean('has_recording')->default(false)->after('ended_at');
            $table->string('recording_path')->nullable()->after('has_recording');
            $table->integer('recording_duration')->nullable()->comment('Duration in seconds')->after('recording_path');
            $table->bigInteger('recording_size')->nullable()->comment('Size in bytes')->after('recording_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->dropColumn(['has_recording', 'recording_path', 'recording_duration', 'recording_size']);
        });
    }
};