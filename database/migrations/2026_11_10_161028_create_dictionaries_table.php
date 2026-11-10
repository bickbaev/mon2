<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('dictionary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['dictionary_id', 'active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_items');
        Schema::dropIfExists('dictionaries');
    }
};
