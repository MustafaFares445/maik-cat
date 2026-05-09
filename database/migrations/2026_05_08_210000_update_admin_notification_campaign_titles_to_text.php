<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notification_campaigns', function (Blueprint $table): void {
            $table->text('title_en')->change();
            $table->text('title_ar')->nullable()->change();
            $table->text('title_hu')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('admin_notification_campaigns', function (Blueprint $table): void {
            $table->string('title_en', 150)->change();
            $table->string('title_ar', 150)->nullable()->change();
            $table->string('title_hu', 150)->nullable()->change();
        });
    }
};
