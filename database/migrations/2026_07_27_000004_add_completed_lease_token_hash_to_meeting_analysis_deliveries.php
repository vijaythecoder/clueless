<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_analysis_deliveries', function (Blueprint $table) {
            $table->char('completed_lease_token_hash', 64)->nullable()->after('lease_token');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_analysis_deliveries', function (Blueprint $table) {
            $table->dropColumn('completed_lease_token_hash');
        });
    }
};
