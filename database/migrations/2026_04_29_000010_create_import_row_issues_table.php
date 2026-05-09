<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_row_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->string('excel_sheet');
            $table->unsignedInteger('excel_row');
            $table->string('issue_code', 64);
            $table->json('raw_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')
                ->on('import_batches')
                ->cascadeOnDelete();

            $table->index(['batch_id', 'issue_code']);
            $table->index(['batch_id', 'excel_sheet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_issues');
    }
};
