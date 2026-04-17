<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->index('serial_code');
            $table->index('model');
        });

        Schema::table('extra_codes', function (Blueprint $table) {
            $table->index('code');
            $table->index(['item_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('extra_codes', function (Blueprint $table) {
            $table->dropIndex(['item_id', 'code']);
            $table->dropIndex(['code']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['serial_code']);
            $table->dropIndex(['model']);
        });
    }
};

