<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ItemImageAuditOptions
{
    public function __construct(
        public bool $visualComparison,
        public float $visualThreshold,
    ) {}
}
