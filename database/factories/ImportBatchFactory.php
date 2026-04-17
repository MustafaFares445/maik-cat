<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'file_name' => 'test-import.xlsx',
            'imported_by' => fake()->safeEmail(),
            'status' => 'completed',
            'error_message' => null,
            'rows_inserted' => fake()->numberBetween(10, 100),
            'rows_skipped' => 0,
            'rows_flagged' => 0,
            'rows_invalid' => 0,
        ];
    }
}
