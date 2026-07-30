<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_analysis_deliveries', function (Blueprint $table) {
            $table->json('evidence_snapshot')
                ->nullable()
                ->after('completed_lease_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_analysis_deliveries', function (Blueprint $table) {
            $table->dropColumn('evidence_snapshot');
        });
    }
};
