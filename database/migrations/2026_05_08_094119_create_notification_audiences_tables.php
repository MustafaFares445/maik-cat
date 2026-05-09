<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_audiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_audience_user', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_audience_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('notification_audience_id')
                ->references('id')
                ->on('notification_audiences')
                ->cascadeOnDelete();

            $table->unique(['notification_audience_id', 'user_id'], 'audience_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_audience_user');
        Schema::dropIfExists('notification_audiences');
    }
};
