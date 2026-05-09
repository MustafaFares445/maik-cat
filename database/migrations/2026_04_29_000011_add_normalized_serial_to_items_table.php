<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'normalized_serial')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('normalized_serial')->nullable()->after('serial_code');
            });
        }

        DB::table('items')->update([
            'normalized_serial' => DB::raw($this->normalizedSerialSql('serial_code')),
        ]);

        $duplicateExists = DB::table('items')
            ->selectRaw('car_group_id')
            ->groupByRaw(
                "car_group_id, COALESCE(normalized_serial, ''), COALESCE(weight_kg, -1), COALESCE(pt_ppm, -1), COALESCE(pd_ppm, -1), COALESCE(rh_ppm, -1)"
            )
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicateExists) {
            throw new RuntimeException(
                'Cannot add normalized serial uniqueness index because duplicate rows already exist.'
            );
        }

        $hasLegacyUnique = $this->indexExists('items', 'uix_item_assay');
        $hasNormalizedIndex = $this->indexExists('items', 'idx_items_normalized_serial');
        $hasGroupNormalizedIndex = $this->indexExists('items', 'idx_items_group_normalized_serial');
        $hasNewUnique = $this->indexExists('items', 'uix_items_group_normalized_assay');

        Schema::table('items', function (Blueprint $table) use (
            $hasLegacyUnique,
            $hasNormalizedIndex,
            $hasGroupNormalizedIndex,
            $hasNewUnique
        ) {
            if ($hasLegacyUnique) {
                $table->dropUnique('uix_item_assay');
            }

            if (! $hasNormalizedIndex) {
                $table->index('normalized_serial', 'idx_items_normalized_serial');
            }

            if (! $hasGroupNormalizedIndex) {
                $table->index(['car_group_id', 'normalized_serial'], 'idx_items_group_normalized_serial');
            }

            if (! $hasNewUnique) {
                $table->unique(
                    ['car_group_id', 'normalized_serial', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm'],
                    'uix_items_group_normalized_assay'
                );
            }
        });
    }

    public function down(): void
    {
        $hasLegacyUnique = $this->indexExists('items', 'uix_item_assay');
        $hasNormalizedIndex = $this->indexExists('items', 'idx_items_normalized_serial');
        $hasGroupNormalizedIndex = $this->indexExists('items', 'idx_items_group_normalized_serial');
        $hasNewUnique = $this->indexExists('items', 'uix_items_group_normalized_assay');

        Schema::table('items', function (Blueprint $table) use (
            $hasLegacyUnique,
            $hasNormalizedIndex,
            $hasGroupNormalizedIndex,
            $hasNewUnique
        ) {
            if ($hasNewUnique) {
                $table->dropUnique('uix_items_group_normalized_assay');
            }

            if (! $hasLegacyUnique) {
                $table->unique(
                    ['serial_code', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm'],
                    'uix_item_assay'
                );
            }

            if ($hasGroupNormalizedIndex) {
                $table->dropIndex('idx_items_group_normalized_serial');
            }

            if ($hasNormalizedIndex) {
                $table->dropIndex('idx_items_normalized_serial');
            }
        });

        if (Schema::hasColumn('items', 'normalized_serial')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('normalized_serial');
            });
        }
    }

    private function normalizedSerialSql(string $column): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '/', ''), '.', ''))";
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
