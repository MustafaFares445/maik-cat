<?php

namespace App\Data;

final readonly class EcotradeProductData
{
    public function __construct(
        public string $brandSlug,
        public string $brandName,
        public string $brandPageUrl,
        public string $productUrl,
        public string $serialCode,
        public string $productName,
        public ?string $thumbnailUrl,
        public ?string $mainImageUrl,
        public array $imageUrls,
        public int $imageCount,
        public ?string $cardPrice,
        public array $cardTexts,
        public string $sourceHash,
        public array $raw,
        public ?string $invalidReason = null,
    ) {}

    public function isValid(): bool
    {
        return $this->invalidReason === null;
    }

    public function detailsPayload(): array
    {
        return [
            'source' => 'ecotrade',
            'brand_slug' => $this->brandSlug,
            'brand_name' => $this->brandName,
            'product_url' => $this->productUrl,
            'brand_page_url' => $this->brandPageUrl,
            'product_name' => $this->productName,
            'serial_code' => $this->serialCode,
            'thumbnail_url' => $this->thumbnailUrl,
            'main_image_url' => $this->mainImageUrl,
            'image_urls' => $this->imageUrls,
            'image_count' => $this->imageCount,
            'card_price' => $this->cardPrice,
            'card_texts' => $this->cardTexts,
        ];
    }
}
