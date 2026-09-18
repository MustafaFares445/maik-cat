<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('authorized_device_token')->nullable()->after('fcm_token');
            $table->timestamp('device_bound_at')->nullable()->after('authorized_device_token');
        });

        // Preserve the currently known mobile installation for existing users.
        DB::table('users')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '<>', '')
            ->whereNull('authorized_device_token')
            ->update([
                'authorized_device_token' => DB::raw('fcm_token'),
                'device_bound_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['authorized_device_token', 'device_bound_at']);
        });
    }
};
