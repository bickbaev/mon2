<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_types', function (Blueprint $table): void {
            $table->json('definition_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_types', function (Blueprint $table): void {
            $table->dropColumn('definition_snapshot');
        });
    }
};
