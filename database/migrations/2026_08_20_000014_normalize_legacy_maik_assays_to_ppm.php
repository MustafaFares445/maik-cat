<?php

use App\Support\Items\ItemAssayFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string LEGACY_SOURCE = 'legacy_maik_g_per_kg';

    public function up(): void
    {
        DB::table('items')
            ->whereNull('source')
            ->where(function ($query): void {
                $query->where('pt_ppm', '>', 0)->where('pt_ppm', '<', 100)
                    ->orWhere(function ($query): void {
                        $query->where('pd_ppm', '>', 0)->where('pd_ppm', '<', 100);
                    })
                    ->orWhere(function ($query): void {
                        $query->where('rh_ppm', '>', 0)->where('rh_ppm', '<', 100);
                    });
            })
            ->orderBy('id')
            ->chunkById(250, function ($items): void {
                foreach ($items as $item) {
                    $ptPpm = $this->scale($item->pt_ppm);
                    $pdPpm = $this->scale($item->pd_ppm);
                    $rhPpm = $this->scale($item->rh_ppm);
                    $fingerprint = ItemAssayFingerprint::make(
                        $item->car_group_id,
                        $item->normalized_serial,
                        $item->weight_kg,
                        $ptPpm,
                        $pdPpm,
                        $rhPpm,
                    );

                    $duplicateExists = $fingerprint !== null
                        && DB::table('items')
                            ->where('assay_fingerprint', $fingerprint)
                            ->where('id', '!=', $item->id)
                            ->exists();

                    DB::table('items')->where('id', $item->id)->update([
                        'pt_ppm' => $ptPpm,
                        'pd_ppm' => $pdPpm,
                        'rh_ppm' => $rhPpm,
                        'assay_fingerprint' => $duplicateExists ? null : $fingerprint,
                        'source' => self::LEGACY_SOURCE,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('items')
            ->where('source', self::LEGACY_SOURCE)
            ->orderBy('id')
            ->chunkById(250, function ($items): void {
                foreach ($items as $item) {
                    $ptPpm = $this->unscale($item->pt_ppm);
                    $pdPpm = $this->unscale($item->pd_ppm);
                    $rhPpm = $this->unscale($item->rh_ppm);

                    DB::table('items')->where('id', $item->id)->update([
                        'pt_ppm' => $ptPpm,
                        'pd_ppm' => $pdPpm,
                        'rh_ppm' => $rhPpm,
                        'assay_fingerprint' => ItemAssayFingerprint::make(
                            $item->car_group_id,
                            $item->normalized_serial,
                            $item->weight_kg,
                            $ptPpm,
                            $pdPpm,
                            $rhPpm,
                        ),
                        'source' => null,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    private function scale(mixed $assay): ?float
    {
        return $assay === null ? null : (float) $assay * 1000;
    }

    private function unscale(mixed $assay): ?float
    {
        return $assay === null ? null : (float) $assay / 1000;
    }
};
