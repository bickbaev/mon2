<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_demo_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('position');
            $table->string('phone', 50);
            $table->string('email');
        });
        Schema::create('complex_demo_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            $table->unsignedInteger('participants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_demo_events');
        Schema::dropIfExists('complex_demo_contacts');
    }
};
