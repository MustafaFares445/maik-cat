<?php

namespace App\Data;

use App\Models\Item;

final readonly class EcotradeProductImageCandidate
{
    public function __construct(
        public Item $item,
        public EcotradeProductData $product,
        public string $sourceImageUrl,
    ) {}
}
