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
            CarGroup::create(['id' => Str::uuid(), ...$group]);
        }
    }
}
