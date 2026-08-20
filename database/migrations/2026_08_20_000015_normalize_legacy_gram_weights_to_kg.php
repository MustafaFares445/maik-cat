<?php

use App\Support\Items\ItemAssayFingerprint;
use App\Support\Items\LegacyItemWeightNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('items')
            ->where('weight_kg', '>', 50)
            ->orderBy('id')
            ->chunkById(250, function ($items): void {
                foreach ($items as $item) {
                    $weightKg = LegacyItemWeightNormalizer::toKilograms((float) $item->weight_kg);
                    $fingerprint = ItemAssayFingerprint::make(
                        $item->car_group_id,
                        $item->normalized_serial,
                        $weightKg,
                        $item->pt_ppm,
                        $item->pd_ppm,
                        $item->rh_ppm,
                    );

                    $duplicateExists = $fingerprint !== null
                        && DB::table('items')
                            ->where('assay_fingerprint', $fingerprint)
                            ->where('id', '!=', $item->id)
                            ->exists();

                    DB::table('items')->where('id', $item->id)->update([
                        'weight_kg' => $weightKg,
                        'assay_fingerprint' => $duplicateExists ? null : $fingerprint,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // This repairs values stored in the wrong unit and must not reintroduce invalid data.
    }
};
