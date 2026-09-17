<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_filter_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->unique();
            $table->uuid('filter_item_id')->nullable()->index();
            $table->string('filter_serial')->nullable()->index();
            $table->string('detection_method', 60)->default('manual');
            $table->string('confidence', 20)->default('manual');
            $table->string('status', 30)->default('needs_review')->index();
            $table->decimal('filter_weight_override', 10, 4)->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('filter_item_id')->references('id')->on('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_filter_mappings');
    }
};
