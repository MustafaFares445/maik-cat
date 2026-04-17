<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metal_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('pt_usd_per_oz', 10, 4)->nullable();
            $table->decimal('pd_usd_per_oz', 10, 4)->nullable();
            $table->decimal('rh_usd_per_oz', 10, 4)->nullable();
            $table->string('source')->default('kitco');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metal_prices');
    }
};
