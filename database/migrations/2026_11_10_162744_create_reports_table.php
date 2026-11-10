<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('new');
            $table->timestamps();
            $table->unsignedBigInteger('base_node_id')->nullable()->storedAs('CASE WHEN campaign_id IS NULL THEN node_id ELSE NULL END');
            $table->unique(['report_type_id', 'campaign_id', 'node_id']);
            $table->unique(['report_type_id', 'base_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
