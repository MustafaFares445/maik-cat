<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->unsignedInteger('excel_row');
            $table->string('excel_sheet');
            $table->json('payload');
            $table->uuid('existing_item_id');
            $table->string('status')->default('pending');
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')
                ->on('import_batches')
                ->cascadeOnDelete();

            $table->foreign('existing_item_id')
                ->references('id')
                ->on('items');

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_reviews');
    }
};

