<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Data;

class CalculatorEstimateData extends Data
{
    public function __construct(
        #[Exists('items', 'id')]
        public string $item_id,
        #[Between(0, 1)]
        public ?float $recovery_rate = 0.8,
    ) {}
}

