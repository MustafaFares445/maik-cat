<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('fcm_token');
            $table->string('preferred_language', 5)->default('en')->after('is_active');

            $table->index(['is_active', 'preferred_language'], 'users_active_language_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_active_language_index');
            $table->dropColumn(['is_active', 'preferred_language']);
        });
    }
};
