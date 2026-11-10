<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('kind');
            $table->string('title');
            $table->boolean('is_base')->default(false);
            $table->boolean('is_required')->default(false);
            $table->boolean('auto_create')->default(false);
            $table->boolean('excel_enabled')->default(true);
            $table->boolean('list_allows_empty')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_types');
    }
};
