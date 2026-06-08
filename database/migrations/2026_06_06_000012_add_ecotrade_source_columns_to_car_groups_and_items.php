<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('car_groups', 'slug')) {
                $table->string('slug')->nullable()->after('name')->index();
            }

            if (! Schema::hasColumn('car_groups', 'source')) {
                $table->string('source')->nullable()->after('region')->index();
            }

            if (! Schema::hasColumn('car_groups', 'source_url')) {
                $table->string('source_url')->nullable()->after('source');
            }
        });

        Schema::table('items', function (Blueprint $table): void {
            if (! Schema::hasColumn('items', 'source')) {
                $table->string('source')->nullable()->after('details')->index();
            }

            if (! Schema::hasColumn('items', 'source_url')) {
                $table->string('source_url')->nullable()->after('source');
            }

            if (! Schema::hasColumn('items', 'source_hash')) {
                $table->string('source_hash', 64)->nullable()->after('source_url')->index();
            }
        });

        Schema::table('car_groups', function (Blueprint $table): void {
            $table->unique(['source', 'slug'], 'car_groups_source_slug_unique');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->unique(['source', 'source_hash'], 'items_source_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropUnique('items_source_hash_unique');

            if (Schema::hasColumn('items', 'source_hash')) {
                $table->dropColumn('source_hash');
            }

            if (Schema::hasColumn('items', 'source_url')) {
                $table->dropColumn('source_url');
            }

            if (Schema::hasColumn('items', 'source')) {
                $table->dropColumn('source');
            }
        });

        Schema::table('car_groups', function (Blueprint $table): void {
            $table->dropUnique('car_groups_source_slug_unique');

            if (Schema::hasColumn('car_groups', 'source_url')) {
                $table->dropColumn('source_url');
            }

            if (Schema::hasColumn('car_groups', 'source')) {
                $table->dropColumn('source');
            }

            if (Schema::hasColumn('car_groups', 'slug')) {
                $table->dropColumn('slug');
            }
        });
    }
};
