<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_groups', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->after('region');
            $table->foreign('parent_id')
                ->references('id')
                ->on('car_groups')
                ->nullOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('car_groups', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
