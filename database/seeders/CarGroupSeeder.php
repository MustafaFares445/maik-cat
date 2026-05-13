<?php

namespace Database\Seeders;

use App\Models\CarGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CarGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            // European
            ['name' => 'AUDI VW',          'excel_sheet_name' => 'AUDI VW',          'region' => 'European'],
            ['name' => 'BMW',              'excel_sheet_name' => 'BMW',              'region' => 'European'],
            ['name' => 'MERCEDES',         'excel_sheet_name' => 'MERCEDES',         'region' => 'European'],
            ['name' => 'PSA',              'excel_sheet_name' => 'PSA',              'region' => 'European'],
            ['name' => 'OPEL',             'excel_sheet_name' => 'OPEL',             'region' => 'European'],
            ['name' => 'FIAT',             'excel_sheet_name' => 'FIAT',             'region' => 'European'],
            ['name' => 'IVECO',            'excel_sheet_name' => 'IVECO',            'region' => 'European'],
            ['name' => 'RENAULT',          'excel_sheet_name' => 'RENAULT',          'region' => 'European'],
            ['name' => 'SWEDEN',           'excel_sheet_name' => 'SWEDEN',           'region' => 'European'],
            ['name' => 'ROVER&LAND ROVER', 'excel_sheet_name' => 'ROVER&LAND ROVER', 'region' => 'European'],
            ['name' => 'JAGUAR',           'excel_sheet_name' => 'JAGUAR',           'region' => 'European'],
            ['name' => 'RAZNI',            'excel_sheet_name' => 'RAZNI',            'region' => 'European'],
            // Asian
            ['name' => 'JAPAN',            'excel_sheet_name' => 'JAPAN',            'region' => 'Asian'],
            ['name' => 'KOREA',            'excel_sheet_name' => 'KOREA',            'region' => 'Asian'],
            // American
            ['name' => 'USA',              'excel_sheet_name' => 'USA',              'region' => 'American'],
            ['name' => 'FORD',             'excel_sheet_name' => 'FORD',             'region' => 'American'],
        ];

        foreach ($groups as $group) {
            $carGroup = CarGroup::query()->firstOrCreate(
                ['excel_sheet_name' => $group['excel_sheet_name']],
                ['id' => Str::uuid(), ...$group],
            );

            $this->attachSvgImage($carGroup);
        }
    }

    private function attachSvgImage(CarGroup $group): void
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
}
