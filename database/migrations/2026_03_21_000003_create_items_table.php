<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_batch_id');
            $table->uuid('car_group_id');
            $table->string('model')->nullable();
            $table->string('serial_code')->nullable();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->decimal('pt_ppm', 10, 4)->nullable();
            $table->decimal('pd_ppm', 10, 4)->nullable();
            $table->decimal('rh_ppm', 10, 4)->nullable();
            $table->string('shape_code', 20)->nullable();
            $table->text('details')->nullable();
            $table->timestamps();

            $table->foreign('import_batch_id')
                ->references('id')
                ->on('import_batches')
                ->cascadeOnDelete();

            $table->foreign('car_group_id')
                ->references('id')
                ->on('car_groups');

            $table->unique([
                'serial_code',
                'weight_kg',
                'pt_ppm',
                'pd_ppm',
                'rh_ppm',
            ], 'uix_item_assay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};

