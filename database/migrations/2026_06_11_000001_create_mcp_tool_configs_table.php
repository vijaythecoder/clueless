<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('server_label')->unique();
            $table->string('server_url')->nullable();
            $table->string('connector_id')->nullable();
            $table->text('authorization')->nullable();
            $table->text('headers')->nullable();
            $table->json('allowed_tools')->nullable();
            $table->string('require_approval')->default('never');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_read_only')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'is_read_only']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_configs');
    }
};
