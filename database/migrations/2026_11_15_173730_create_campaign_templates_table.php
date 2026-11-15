<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('creator_node_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->foreignId('report_type_id')->constrained()->restrictOnDelete();
            $table->string('title')->nullable();
            $table->string('periodicity');
            $table->unsignedTinyInteger('start_weekday')->nullable();
            $table->unsignedTinyInteger('start_day')->nullable();
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedSmallInteger('close_after_days')->nullable();
            $table->string('start_status');
            $table->unsignedSmallInteger('remind_days_before')->nullable();
            $table->date('last_spawned_for')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('campaign_template_nodes', function (Blueprint $table): void {
            $table->foreignId('template_id')->constrained('campaign_templates')->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->primary(['template_id', 'node_id']);
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unique(['template_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropUnique(['template_id', 'starts_at']);
        });
        Schema::dropIfExists('campaign_template_nodes');
        Schema::dropIfExists('campaign_templates');
    }
};
