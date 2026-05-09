<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('sent_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('audience_mode', 20);
            $table->uuid('audience_id')->nullable();
            $table->string('type')->default('generale_notification');
            $table->string('title_en', 150);
            $table->text('body_en');
            $table->string('title_ar', 150)->nullable();
            $table->text('body_ar')->nullable();
            $table->string('title_hu', 150)->nullable();
            $table->text('body_hu')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['audience_mode', 'status'], 'admin_campaigns_mode_status_index');
            $table->index('sent_at', 'admin_campaigns_sent_at_index');
        });

        Schema::create('admin_notification_campaign_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('preferred_language', 5)->default('en');
            $table->string('language_used', 5)->default('en');
            $table->uuid('notification_id')->nullable();
            $table->string('delivery_status', 20)->default('pending');
            $table->string('fcm_message_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')
                ->references('id')
                ->on('admin_notification_campaigns')
                ->cascadeOnDelete();

            $table->unique(['campaign_id', 'user_id'], 'campaign_user_unique');
            $table->index(['delivery_status', 'sent_at'], 'campaign_recipients_status_sent_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_campaign_recipients');
        Schema::dropIfExists('admin_notification_campaigns');
    }
};
