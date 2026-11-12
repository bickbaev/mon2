<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_simple_data', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count');
            $table->text('description');
            $table->foreignId('organization_type')->constrained('dictionary_items')->restrictOnDelete();
            $table->date('date');
            $table->json('attachment')->nullable();
        });
        Schema::create('demo_event_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            $table->unsignedInteger('participants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_event_rows');
        Schema::dropIfExists('demo_simple_data');
    }
};
