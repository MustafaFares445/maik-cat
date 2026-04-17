<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->string('code');
            $table->string('source')->default('excel_import');
            $table->timestamps();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();

            $table->index(['item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_codes');
    }
};

