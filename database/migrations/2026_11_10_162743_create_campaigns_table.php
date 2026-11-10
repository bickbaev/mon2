<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('creator_node_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->date('starts_at');
            $table->date('ends_at');
            $table->date('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('start_status');
            $table->unsignedSmallInteger('remind_days_before')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
