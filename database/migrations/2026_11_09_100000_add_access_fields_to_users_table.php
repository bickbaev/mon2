<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('node_id')->nullable()->constrained('nodes')->restrictOnDelete();
            $table->string('login')->nullable();
            $table->dropUnique(['email']);
            $table->string('email')->nullable()->change();
            $table->string('pending_email')->nullable();
            $table->timestamp('pd_consent_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('can_start_campaigns')->default(false);
            $table->boolean('can_manage_structure')->default(false);
            $table->timestamp('blocked_at')->nullable();
        });

        DB::table('users')->orderBy('id')->eachById(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['login' => 'user_'.$user->id]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('login')->nullable(false)->change();
            $table->unique('login');
        });
    }

    public function down(): void
    {
        // Старое ограничение почты восстановимо только без NULL и дублей.
        if (DB::table('users')->whereNull('email')->exists()
            || DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Откат невозможен: почта содержит пустые или повторяющиеся значения.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('node_id');
            $table->dropUnique(['login']);
            $table->dropColumn(['login', 'pending_email', 'pd_consent_at', 'is_admin', 'can_start_campaigns', 'can_manage_structure', 'blocked_at']);
            $table->string('email')->nullable(false)->change();
            $table->unique('email');
        });
    }
};
