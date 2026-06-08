<?php

namespace Database\Seeders;

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ExtraCode;
use App\Models\ImportBatch;
use App\Models\MetalPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MobileFakeDataSeeder extends Seeder
{
    public function run(): void
    {
        if (ImportBatch::query()->where('file_name', 'mobile-fake-data.xlsx')->exists()) {
            return;
        }

        $batch = ImportBatch::factory()->create([
            'file_name' => 'mobile-fake-data.xlsx',
            'imported_by' => 'faker@system.local',
            'status' => 'completed',
        ]);

        $groups = CarGroup::query()->get();

        if ($groups->isEmpty()) {
            $groups = CarGroup::factory()->count(6)->create();
        }

        $converters = collect(range(1, 80))
            ->map(function (int $index) use ($batch, $groups): Item {
                $converter = Item::factory()->make();
                $converter->car_group_id = $groups->random()->id;
                $converter->serial_code = 'FX-' . now()->format('ymd') . '-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
                $converter->model = strtoupper('MODEL-' . Str::random(5));
                $converter->normalized_serial = Item::normalizeSerialValue($converter->serial_code);
                $converter->save();

                ExtraCode::factory()->count(random_int(1, 3))->create([
                    'item_id' => $converter->id,
                    'source' => 'fake_seed',
                ]);

                $this->attachSvgImage($converter);

                return $converter;
            });

        $batch->update(['rows_inserted' => $converters->count()]);

        if (MetalPrice::query()->count() < 14) {
            MetalPrice::factory()->count(18)->create(['source' => 'fake_seed']);
        }
    }

    private function attachSvgImage(Item $converter): void
    {
        if (! method_exists($converter, 'addMediaFromString')) {
            return;
        }

        $serial = $converter->serial_code ?? 'UNKNOWN';
        $model = $converter->model ?? 'ITEM';

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%%" stop-color="#f5efe6"/><stop offset="100%%" stop-color="#ece6de"/></linearGradient></defs><rect width="100%%" height="100%%" fill="url(#g)"/><rect x="50" y="70" width="800" height="460" rx="36" fill="#ffffff" stroke="#c7b79c" stroke-width="4"/><text x="90" y="240" font-size="64" font-family="Arial" fill="#8f4f0d">%s</text><text x="90" y="330" font-size="40" font-family="Arial" fill="#3b3b3b">%s</text><text x="90" y="390" font-size="28" font-family="Arial" fill="#7a7a7a">generated fake seed image</text></svg>',
            htmlspecialchars($serial, ENT_QUOTES),
            htmlspecialchars($model, ENT_QUOTES),
        );

        $converter
            ->addMediaFromString($svg)
            ->usingFileName(strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $serial)) . '-fake.svg')
            ->toMediaCollection('images');
    }
}
