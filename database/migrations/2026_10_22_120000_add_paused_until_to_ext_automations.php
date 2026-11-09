<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ext_automations_automations', function (Blueprint $table): void {
            $table->timestamp('paused_until')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('ext_automations_automations', function (Blueprint $table): void {
            $table->dropColumn('paused_until');
        });
    }
};
