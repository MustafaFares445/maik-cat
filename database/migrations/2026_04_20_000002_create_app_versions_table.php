<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('platform')->unique();
            $table->string('latest_version');
            $table->string('minimum_version');
            $table->string('store_id');
            $table->text('release_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
