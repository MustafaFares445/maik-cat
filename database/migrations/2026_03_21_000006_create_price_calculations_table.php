<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_calculations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id');
            $table->uuid('metal_price_id');
            $table->decimal('recovery_rate', 5, 4)->default(0.8000);
            $table->decimal('pt_value_usd', 12, 4)->nullable();
            $table->decimal('pd_value_usd', 12, 4)->nullable();
            $table->decimal('rh_value_usd', 12, 4)->nullable();
            $table->decimal('total_usd', 12, 4)->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->foreign('item_id')
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();

            $table->foreign('metal_price_id')
                ->references('id')
                ->on('metal_prices');

            $table->index(['item_id', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_calculations');
    }
};

