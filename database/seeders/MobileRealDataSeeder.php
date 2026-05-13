<?php

namespace Database\Seeders;

use App\Models\CarGroup;
use App\Models\Item;
use App\Models\ExtraCode;
use App\Models\ImportBatch;
use App\Models\MetalPrice;
use Illuminate\Database\Seeder;

class MobileRealDataSeeder extends Seeder
{
    public function run(): void
    {
        $batch = ImportBatch::query()->firstOrCreate(
            ['file_name' => 'mobile-real-data.xlsx'],
            [
                'imported_by' => 'seeder@system.local',
                'status' => 'completed',
                'rows_inserted' => 0,
                'rows_skipped' => 0,
                'rows_flagged' => 0,
                'rows_invalid' => 0,
            ],
        );

        $groups = $this->resolveGroups();

        $records = [
            [
                'group' => 'OPEL',
                'model' => 'VAUXHAL',
                'serial_code' => 'GM01',
                'weight_kg' => 0.95,
                'pt_ppm' => 1760,
                'pd_ppm' => 140,
                'rh_ppm' => 279,
                'shape_code' => 'ST',
                'details' => 'From OPEL sheet GM01 reference.',
                'extra_codes' => ['25129131', '111493'],
            ],
            [
                'group' => 'OPEL',
                'model' => 'VAUXHAL',
                'serial_code' => 'GM01',
                'weight_kg' => 0.98,
                'pt_ppm' => 1726,
                'pd_ppm' => 274,
                'rh_ppm' => 311,
                'shape_code' => 'ST',
                'details' => 'Variant from OPEL sheet with higher Pd.',
                'extra_codes' => ['T3'],
            ],
            [
                'group' => 'OPEL',
                'model' => 'VAUXHAL',
                'serial_code' => 'GM03',
                'weight_kg' => 1.20,
                'pt_ppm' => 1520,
                'pd_ppm' => 0,
                'rh_ppm' => 320,
                'shape_code' => 'M',
                'details' => 'OPEL GM03 medium body sample.',
                'extra_codes' => ['T5', '0245F71'],
            ],
            [
                'group' => 'OPEL',
                'model' => 'VAUXHAL',
                'serial_code' => 'GM04',
                'weight_kg' => 1.50,
                'pt_ppm' => 1380,
                'pd_ppm' => 0,
                'rh_ppm' => 290,
                'shape_code' => 'LM',
                'details' => 'Germany source sample from details note.',
                'extra_codes' => ['T1', '2713', 'S62'],
            ],
            [
                'group' => 'OPEL',
                'model' => 'VAUXHAL',
                'serial_code' => 'GM10',
                'weight_kg' => 1.62,
                'pt_ppm' => 1852,
                'pd_ppm' => 0,
                'rh_ppm' => 298,
                'shape_code' => 'LM',
                'details' => 'High Pt value sample.',
                'extra_codes' => ['3223F24'],
            ],
            [
                'group' => 'JAPAN',
                'model' => 'TOYOTA PRIUS',
                'serial_code' => '42004-9842',
                'weight_kg' => 2.00,
                'pt_ppm' => 2027.11,
                'pd_ppm' => 1497.71,
                'rh_ppm' => 10076.15,
                'shape_code' => 'ST',
                'details' => 'Hybrid Toyota sample aligned with mobile mock.',
                'extra_codes' => ['42004', '9842', 'PRIUS'],
            ],
            [
                'group' => 'JAPAN',
                'model' => 'TOYOTA COROLLA',
                'serial_code' => '83910-1210',
                'weight_kg' => 1.35,
                'pt_ppm' => 1460.00,
                'pd_ppm' => 820.00,
                'rh_ppm' => 270.00,
                'shape_code' => 'ST',
                'details' => 'Corolla oxygen sensor reference.',
                'extra_codes' => ['83910', '1210'],
            ],
            [
                'group' => 'JAPAN',
                'model' => 'LEXUS RX350',
                'serial_code' => '56020-7741',
                'weight_kg' => 1.85,
                'pt_ppm' => 1710.00,
                'pd_ppm' => 940.00,
                'rh_ppm' => 300.00,
                'shape_code' => 'LM',
                'details' => 'Lexus RX sample from app cards.',
                'extra_codes' => ['56020', '7741', 'RX350'],
            ],
            [
                'group' => 'KOREA',
                'model' => 'KIA HYUNDAI',
                'serial_code' => '90469364',
                'weight_kg' => 1.10,
                'pt_ppm' => 1523.00,
                'pd_ppm' => 0,
                'rh_ppm' => 210.00,
                'shape_code' => 'ST',
                'details' => 'Korea sample for category browsing.',
                'extra_codes' => ['2393', 'B0', 'B1'],
            ],
            [
                'group' => 'BMW',
                'model' => 'BMW DPF',
                'serial_code' => 'BMW-5802',
                'weight_kg' => 2.30,
                'pt_ppm' => 1100.00,
                'pd_ppm' => 640.00,
                'rh_ppm' => 180.00,
                'shape_code' => 'LM',
                'details' => 'Diesel DPF representative sample.',
                'extra_codes' => ['DPF', '5802'],
            ],
        ];

        foreach ($records as $record) {
            $converter = Item::query()->firstOrCreate(
                [
                    'serial_code' => $record['serial_code'],
                    'weight_kg' => $record['weight_kg'],
                    'pt_ppm' => $record['pt_ppm'],
                    'pd_ppm' => $record['pd_ppm'],
                    'rh_ppm' => $record['rh_ppm'],
                ],
                [
                    'car_group_id' => $groups[$record['group']]->id,
                    'model' => $record['model'],
                    'shape_code' => $record['shape_code'],
                    'details' => $record['details'],
                ],
            );

            foreach ($record['extra_codes'] as $code) {
                ExtraCode::query()->firstOrCreate([
                    'item_id' => $converter->id,
                    'code' => $code,
                ], [
                    'source' => 'real_seed',
                ]);
            }

            $this->attachSvgImage($converter, $record['serial_code'], $record['group']);
        }

        $batch->update(['rows_inserted' => count($records)]);

        $this->seedMetalPrices();
    }

    /** @return array<string, CarGroup> */
    private function resolveGroups(): array
    {
        $result = [];

        foreach (['OPEL', 'JAPAN', 'KOREA', 'BMW'] as $sheetName) {
            $result[$sheetName] = CarGroup::query()->firstOrCreate(
                ['excel_sheet_name' => $sheetName],
                [
                    'name' => $sheetName,
                    'region' => in_array($sheetName, ['JAPAN', 'KOREA'], true) ? 'Asian' : 'European',
                ],
            );

            $this->attachGroupImage($result[$sheetName]);
        }

        return $result;
    }

    private function attachGroupImage(CarGroup $group): void
    {
        if (! method_exists($group, 'addMediaFromString')) {
            return;
        }

        if (method_exists($group, 'getFirstMedia') && $group->getFirstMedia('images')) {
            return;
        }

        $region = (string) ($group->region ?? 'Global');
        $name = (string) ($group->name ?? 'CAR GROUP');

        $color = match ($region) {
            'Asian' => '#0f6ba8',
            'American' => '#8b1f2c',
            default => '#9a4b00',
        };

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="100%%" height="100%%" fill="#f4f1ea"/><rect x="60" y="60" width="1080" height="680" rx="40" fill="%s" opacity="0.14"/><text x="100" y="320" font-size="84" font-family="Arial" fill="#2f2f2f">%s</text><text x="100" y="430" font-size="50" font-family="Arial" fill="#4f4f4f">%s region</text><text x="100" y="520" font-size="36" font-family="Arial" fill="#6f6f6f">car group image seed</text></svg>',
            $color,
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($region, ENT_QUOTES),
        );

        $fileName = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $group->excel_sheet_name ?: $name)) . '-group.svg';

        $group
            ->addMediaFromString($svg)
            ->usingFileName($fileName)
            ->toMediaCollection('images');
    }

    private function attachSvgImage(Item $converter, string $serialCode, string $group): void
    {
        if (! method_exists($converter, 'addMediaFromString')) {
            return;
        }

        if (method_exists($converter, 'getFirstMedia') && $converter->getFirstMedia('images')) {
            return;
        }

        $color = match ($group) {
            'JAPAN' => '#0f6ba8',
            'KOREA' => '#0f8a63',
            'BMW' => '#1f4f8f',
            default => '#9a4b00',
        };

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="100%%" height="100%%" fill="#f4f1ea"/><rect x="60" y="60" width="1080" height="680" rx="40" fill="%s" opacity="0.12"/><text x="100" y="300" font-size="90" font-family="Arial" fill="#2f2f2f">Maik Cars</text><text x="100" y="420" font-size="64" font-family="Arial" fill="#2f2f2f">%s</text><text x="100" y="510" font-size="48" font-family="Arial" fill="#555">%s item image seed</text></svg>',
            $color,
            htmlspecialchars($serialCode, ENT_QUOTES),
            htmlspecialchars($group, ENT_QUOTES),
        );

        $converter
            ->addMediaFromString($svg)
            ->usingFileName(strtolower($serialCode) . '.svg')
            ->toMediaCollection('images');
    }

    private function seedMetalPrices(): void
    {
        if (MetalPrice::query()->where('source', 'real_seed')->exists()) {
            return;
        }

        $ptBase = 1550.0;
        $pdBase = 980.0;
        $rhBase = 4450.0;

        for ($i = 20; $i >= 0; $i--) {
            $day = now()->subDays($i);

            MetalPrice::query()->create([
                'pt_usd_per_oz' => round($ptBase + sin($i / 2.8) * 90 + ($i % 2 === 0 ? 10 : -8), 4),
                'pd_usd_per_oz' => round($pdBase + cos($i / 3.1) * 70 + ($i % 3 === 0 ? 7 : -5), 4),
                'rh_usd_per_oz' => round($rhBase + sin($i / 4.2) * 230 + ($i % 4 === 0 ? 22 : -16), 4),
                'source' => 'real_seed',
                'fetched_at' => $day->copy()->setTime(12, 0, 0),
                'created_at' => $day->copy()->setTime(12, 0, 0),
                'updated_at' => $day->copy()->setTime(12, 0, 0),
            ]);
        }
    }
}
