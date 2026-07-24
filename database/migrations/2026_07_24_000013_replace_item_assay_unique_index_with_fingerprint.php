<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'assay_fingerprint')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->string('assay_fingerprint', 64)->nullable()->after('normalized_serial');
            });
        }

        DB::table('items')
            ->select(['id', 'car_group_id', 'serial_code', 'normalized_serial', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm'])
            ->orderBy('id')
            ->chunk(500, function ($items): void {
                foreach ($items as $item) {
                    DB::table('items')->where('id', $item->id)->update([
                        'assay_fingerprint' => $this->fingerprint(
                            $item->car_group_id,
                            $item->normalized_serial ?: $this->normalizeSerial($item->serial_code),
                            $item->weight_kg,
                            $item->pt_ppm,
                            $item->pd_ppm,
                            $item->rh_ppm,
                        ),
                    ]);
                }
            });

        $duplicateFingerprints = DB::table('items')
            ->whereNotNull('assay_fingerprint')
            ->select('assay_fingerprint')
            ->groupBy('assay_fingerprint')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('assay_fingerprint');

        foreach ($duplicateFingerprints as $fingerprint) {
            $duplicateIds = DB::table('items')
                ->where('assay_fingerprint', $fingerprint)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            if ($duplicateIds->isNotEmpty()) {
                DB::table('items')
                    ->whereIn('id', $duplicateIds->all())
                    ->update(['assay_fingerprint' => null]);
            }
        }

        $hasLegacyUnique = $this->indexExists('items', 'uix_item_assay');
        $hasNormalizedUnique = $this->indexExists('items', 'uix_items_group_normalized_assay');
        $hasFingerprintUnique = $this->indexExists('items', 'uix_items_assay_fingerprint');

        Schema::table('items', function (Blueprint $table) use (
            $hasLegacyUnique,
            $hasNormalizedUnique,
            $hasFingerprintUnique,
        ): void {
            if ($hasLegacyUnique) {
                $table->dropUnique('uix_item_assay');
            }

            if ($hasNormalizedUnique) {
                $table->dropUnique('uix_items_group_normalized_assay');
            }

            if (! $hasFingerprintUnique) {
                $table->unique('assay_fingerprint', 'uix_items_assay_fingerprint');
            }
        });
    }

    public function down(): void
    {
        $hasFingerprintUnique = $this->indexExists('items', 'uix_items_assay_fingerprint');
        $hasNormalizedUnique = $this->indexExists('items', 'uix_items_group_normalized_assay');

        Schema::table('items', function (Blueprint $table) use ($hasFingerprintUnique, $hasNormalizedUnique): void {
            if ($hasFingerprintUnique) {
                $table->dropUnique('uix_items_assay_fingerprint');
            }

            if (! $hasNormalizedUnique) {
                $table->unique(
                    ['car_group_id', 'normalized_serial', 'weight_kg', 'pt_ppm', 'pd_ppm', 'rh_ppm'],
                    'uix_items_group_normalized_assay',
                );
            }
        });

        if (Schema::hasColumn('items', 'assay_fingerprint')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn('assay_fingerprint');
            });
        }
    }

    private function fingerprint(
        mixed $groupId,
        mixed $normalizedSerial,
        mixed $weightKg,
        mixed $ptPpm,
        mixed $pdPpm,
        mixed $rhPpm,
    ): ?string {
        $groupId = trim((string) $groupId);
        $normalizedSerial = $this->normalizeSerial($normalizedSerial);
        $weight = (float) ($weightKg ?? 0);
        $hasMetal = (float) ($ptPpm ?? 0) > 0
            || (float) ($pdPpm ?? 0) > 0
            || (float) ($rhPpm ?? 0) > 0;

        if ($groupId === '' || $normalizedSerial === '' || $weight <= 0 || ! $hasMetal) {
            return null;
        }

        return hash('sha256', implode('|', [
            $groupId,
            $normalizedSerial,
            $this->decimal($weightKg, 3),
            $this->decimal($ptPpm, 4),
            $this->decimal($pdPpm, 4),
            $this->decimal($rhPpm, 4),
        ]));
    }

    private function normalizeSerial(mixed $serial): string
    {
        $value = mb_strtoupper(trim((string) $serial));

        return preg_replace('/[\s\-\.\/]+/u', '', $value) ?? $value;
    }

    private function decimal(mixed $value, int $precision): string
    {
        if ($value === null || $value === '') {
            return 'null';
        }

        return number_format((float) $value, $precision, '.', '');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $connection->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
