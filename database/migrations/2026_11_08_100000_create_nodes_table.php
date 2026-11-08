<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->string('type');
            $table->string('name');
            // ascii: путь состоит из цифр и "/", и индекс на 1000 байт укладывается в лимит InnoDB
            $table->string('path', 1000)->charset('ascii')->collation('ascii_bin')->index();
            $table->unsignedSmallInteger('depth');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
