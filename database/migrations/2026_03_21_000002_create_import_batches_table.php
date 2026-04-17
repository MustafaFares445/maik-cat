<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('file_name');
            $table->string('imported_by');
            $table->string('status')->default('processing');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_flagged')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
