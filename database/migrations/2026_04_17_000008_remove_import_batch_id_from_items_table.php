<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->uuid('import_batch_id')->after('id');

            $table->foreign('import_batch_id')
                ->references('id')
                ->on('import_batches')
                ->cascadeOnDelete();
        });
    }
};
