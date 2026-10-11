<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_automations_automations', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->string('name', 100);
            $table->boolean('enabled')->default(true);
            $table->boolean('skip_offline')->default(false);
            $table->json('trigger');
            $table->json('steps');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->index(['enabled', 'next_run_at']);
        });

        // A run keeps a copy of the steps it started with, so editing an automation never moves a running cursor.
        Schema::create('ext_automations_runs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('automation_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('status', 16);
            $table->string('source', 16);
            $table->json('steps');
            $table->json('cursor')->nullable();
            $table->json('log')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('wake_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('automation_id')->references('id')->on('ext_automations_automations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['automation_id', 'status']);
            $table->index(['status', 'wake_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_automations_runs');
        Schema::dropIfExists('ext_automations_automations');
    }
};
