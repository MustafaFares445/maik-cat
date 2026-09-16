<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Data\ItemImageAuditOptions;
use App\Models\Item;
use App\Services\Ecotrade\EcotradeImageReferenceIndex;
use App\Services\Ecotrade\EcotradeSourceImageDownloader;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ItemImageIdentityInspector
{
    private const int REFERENCE_CACHE_LIMIT = 128;

    /** @var array<string,array{fingerprint:list<int>|null,error:string|null}> */
    private array $referenceFingerprintCache = [];

    /** @var list<string> */
    private array $referenceCacheOrder = [];

    public function __construct(
        private readonly EcotradeImageReferenceIndex $referenceIndex,
        private readonly EcotradeSourceImageDownloader $downloader,
        private readonly PhpImageSimilarity $imageSimilarity,
    ) {}

    /** @param iterable<int,array<string,mixed>> $records */
    public function buildReferenceIndex(iterable $records): void
    {
        $this->referenceIndex->build($records);
    }

    /** @return array<string,int> */
    public function referenceStatistics(): array
    {
        return $this->referenceIndex->statistics();
    }

    /** @return array<string,mixed> */
    public function inspect(Item $item, ItemImageAuditOptions $options): array
    {
        $media = $item->getFirstMedia('images');
        $referenceMatch = $this->referenceIndex->referencesFor($item);
        $exactSourceHashMatch = $this->sourceHashesMatch($item, $media);
        $classification = $this->classification($media, $referenceMatch, $exactSourceHashMatch);
        $visual = $this->visualComparison($media, $referenceMatch, $options);

        if ($visual['score'] !== null) {
            $classification = $this->classificationWithVisualScore(
                $classification,
                $visual['score'],
                $options->visualThreshold,
            );
        }

        $reference = $referenceMatch['ambiguous'] ? null : ($referenceMatch['references'][0] ?? null);

        return [
            'status' => $classification['status'],
            'reason' => $classification['reason'],
            'item_id' => (string) $item->getKey(),
            'car_group_id' => (string) $item->car_group_id,
            'car_group' => (string) ($item->carGroup?->name ?? ''),
            'serial_code' => (string) $item->serial_code,
            'normalized_serial' => Item::normalizeSerialValue($item->normalized_serial ?: $item->serial_code),
            'extra_codes' => $item->extraCodes->pluck('code')->implode('|'),
            'primary_family_key' => $referenceMatch['primary_family_key'],
            'matched_family_keys' => implode('|', $referenceMatch['family_keys']),
            'reference_count' => count($referenceMatch['references']),
            'item_source_url' => (string) ($item->source_url ?? ''),
            'item_source_hash' => (string) ($item->source_hash ?? ''),
            'media_id' => $media?->getKey(),
            'media_file_name' => (string) ($media?->file_name ?? ''),
            'media_sha256' => $this->mediaHash($media),
            'media_source' => (string) ($media?->getCustomProperty('source') ?? ''),
            'media_source_url' => (string) ($media?->getCustomProperty('source_url') ?? ''),
            'media_source_hash' => (string) ($media?->getCustomProperty('source_hash') ?? ''),
            'current_image_url' => (string) ($media?->getFullUrl() ?? ''),
            'expected_serial_code' => (string) ($reference['serial_code'] ?? ''),
            'expected_product_url' => (string) ($reference['product_url'] ?? ''),
            'expected_image_url' => (string) ($visual['image_url'] ?? ($reference['image_urls'][0] ?? '')),
            'expected_source_hash' => (string) ($reference['source_hash'] ?? ''),
            'visual_score' => $visual['score'],
            'visual_error' => $visual['error'],
        ];
    }

    /** @param array<string,mixed> $referenceMatch @return array{status:string,reason:string} */
    private function classification(?Media $media, array $referenceMatch, bool $exactSourceHashMatch): array
    {
        if (! $media instanceof Media) {
            return $this->classifiedAs('missing_media', 'The item has no image media.');
        }

        if (! is_file($media->getPath())) {
            return $this->classifiedAs('missing_media_file', 'The media database row exists, but its original file is missing.');
        }

        if ($exactSourceHashMatch) {
            return $this->classifiedAs(
                'provenance_match',
                'The item and media source hashes match exactly.',
            );
        }

        if ($referenceMatch['ambiguous']) {
            return $this->classifiedAs('ambiguous_reference', 'More than one Ecotrade product matches the item identity.');
        }

        $provenance = $this->mediaProvenance($media);

        if ($referenceMatch['references'] === []) {
            if ($provenance['references'] !== []) {
                return $this->classifiedAs(
                    'group_mapping_conflict',
                    'No Ecotrade reference matches the item group and serial, but the media provenance resolves to an Ecotrade product under a different group mapping.',
                );
            }

            if ($provenance['recorded']) {
                return $this->classifiedAs(
                    'unknown_media_source',
                    'The media records a source that is not present in this Ecotrade JSON file.',
                );
            }

            return $this->classifiedAs('missing_reference', 'No Ecotrade image reference matches the item identity.');
        }

        $expectedReference = $referenceMatch['references'][0];

        if ($provenance['references'] === []) {
            $status = $provenance['recorded'] ? 'unknown_media_source' : 'missing_provenance';
            $reason = $provenance['recorded']
                ? 'The media records a source that is not present in this Ecotrade JSON file.'
                : 'The media has no Ecotrade source URL or source hash.';

            return $this->classifiedAs($status, $reason);
        }

        foreach ($provenance['references'] as $provenanceReference) {
            if ($this->sameReference($expectedReference, $provenanceReference)) {
                return $this->classifiedAs('provenance_match', 'The media provenance resolves to the expected Ecotrade product.');
            }
        }

        if (count($provenance['references']) > 1) {
            return $this->classifiedAs(
                'ambiguous_media_source',
                'The media provenance resolves to more than one Ecotrade product and cannot confirm a wrong source.',
            );
        }

        return $this->classifiedAs(
            'confirmed_wrong_source',
            'The expected Ecotrade product is unambiguous and the media provenance resolves to a different product or serial.',
        );
    }

    /** @return array{references:list<array<string,mixed>>,recorded:bool} */
    private function mediaProvenance(Media $media): array
    {
        $sourceHash = trim((string) $media->getCustomProperty('source_hash'));
        $sourceUrl = trim((string) $media->getCustomProperty('source_url'));
        $references = [];

        if ($sourceHash !== '') {
            foreach ($this->referenceIndex->referencesForSourceHash($sourceHash) as $reference) {
                $references[$this->referenceIdentityKey($reference)] = $reference;
            }
        }

        if ($sourceUrl !== '') {
            foreach ($this->referenceIndex->referencesForSourceUrl($sourceUrl) as $reference) {
                $references[$this->referenceIdentityKey($reference)] = $reference;
            }
        }

        return [
            'references' => array_values($references),
            'recorded' => $sourceHash !== '' || $sourceUrl !== '',
        ];
    }

    private function sourceHashesMatch(Item $item, ?Media $media): bool
    {
        if (! $media instanceof Media) {
            return false;
        }

        $itemSourceHash = trim((string) ($item->source_hash ?? ''));
        $mediaSourceHash = trim((string) $media->getCustomProperty('source_hash'));

        return $itemSourceHash !== ''
            && $mediaSourceHash !== ''
            && hash_equals($itemSourceHash, $mediaSourceHash);
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function sameReference(array $expected, array $actual): bool
    {
        $expectedSourceHash = trim((string) ($expected['source_hash'] ?? ''));
        $actualSourceHash = trim((string) ($actual['source_hash'] ?? ''));

        if ($expectedSourceHash !== '' && $actualSourceHash !== '' && hash_equals($expectedSourceHash, $actualSourceHash)) {
            return true;
        }

        $expectedProductUrl = $this->normalizeUrl((string) ($expected['product_url'] ?? ''));
        $actualProductUrl = $this->normalizeUrl((string) ($actual['product_url'] ?? ''));

        if ($expectedProductUrl !== '' && $actualProductUrl !== '' && $expectedProductUrl === $actualProductUrl) {
            return true;
        }

        if ($expectedSourceHash !== '' || $actualSourceHash !== '' || $expectedProductUrl !== '' || $actualProductUrl !== '') {
            return false;
        }

        $expectedSerial = Item::normalizeSerialValue($expected['serial_code'] ?? null);
        $actualSerial = Item::normalizeSerialValue($actual['serial_code'] ?? null);

        return $expectedSerial !== '' && $expectedSerial === $actualSerial;
    }

    /** @param array<string,mixed> $reference */
    private function referenceIdentityKey(array $reference): string
    {
        $sourceHash = trim((string) ($reference['source_hash'] ?? ''));

        if ($sourceHash !== '') {
            return 'hash:'.$sourceHash;
        }

        $productUrl = $this->normalizeUrl((string) ($reference['product_url'] ?? ''));

        if ($productUrl !== '') {
            return 'url:'.$productUrl;
        }

        return 'serial:'.Item::normalizeSerialValue($reference['serial_code'] ?? null);
    }

    private function normalizeUrl(string $url): string
    {
        return mb_strtolower(trim($url));
    }

    /** @param array<string,mixed> $referenceMatch @return array{score:float|null,image_url:string|null,error:string|null} */
    private function visualComparison(
        ?Media $media,
        array $referenceMatch,
        ItemImageAuditOptions $options,
    ): array {
        if (! $options->visualComparison || ! $media instanceof Media || $referenceMatch['ambiguous']) {
            return $this->emptyVisualComparison();
        }

        $reference = $referenceMatch['references'][0] ?? null;

        if (! is_array($reference) || ! is_file($media->getPath())) {
            return $this->emptyVisualComparison();
        }

        try {
            $currentFingerprint = $this->imageSimilarity->fingerprintFromPath($media->getPath());
        } catch (RuntimeException $exception) {
            return ['score' => null, 'image_url' => null, 'error' => $exception->getMessage()];
        }

        return $this->bestReferenceScore($currentFingerprint, (array) $reference['image_urls']);
    }

    /** @param list<int> $currentFingerprint @param list<string> $imageUrls @return array{score:float|null,image_url:string|null,error:string|null} */
    private function bestReferenceScore(array $currentFingerprint, array $imageUrls): array
    {
        $bestScore = null;
        $bestImageUrl = null;
        $errors = [];

        foreach ($imageUrls as $imageUrl) {
            $reference = $this->referenceFingerprint($imageUrl);

            if ($reference['fingerprint'] === null) {
                $errors[] = $reference['error'];

                continue;
            }

            $score = $this->imageSimilarity->compare($currentFingerprint, $reference['fingerprint']);

            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $bestImageUrl = $imageUrl;
            }
        }

        return [
            'score' => $bestScore,
            'image_url' => $bestImageUrl,
            'error' => $bestScore === null ? implode(' | ', array_filter($errors)) : null,
        ];
    }

    /** @return array{fingerprint:list<int>|null,error:string|null} */
    private function referenceFingerprint(string $imageUrl): array
    {
        if (isset($this->referenceFingerprintCache[$imageUrl])) {
            return $this->referenceFingerprintCache[$imageUrl];
        }

        try {
            $download = $this->downloader->download($imageUrl);
            $fingerprint = [
                'fingerprint' => $this->imageSimilarity->fingerprintFromBytes($download['bytes']),
                'error' => null,
            ];
        } catch (ConnectionException|RuntimeException $exception) {
            $fingerprint = ['fingerprint' => null, 'error' => $exception->getMessage()];
        }

        $this->rememberFingerprint($imageUrl, $fingerprint);

        return $fingerprint;
    }

    /** @param array{fingerprint:list<int>|null,error:string|null} $fingerprint */
    private function rememberFingerprint(string $imageUrl, array $fingerprint): void
    {
        if (count($this->referenceCacheOrder) >= self::REFERENCE_CACHE_LIMIT) {
            $expiredUrl = array_shift($this->referenceCacheOrder);

            if (is_string($expiredUrl)) {
                unset($this->referenceFingerprintCache[$expiredUrl]);
            }
        }

        $this->referenceCacheOrder[] = $imageUrl;
        $this->referenceFingerprintCache[$imageUrl] = $fingerprint;
    }

    /** @param array{status:string,reason:string} $classification @return array{status:string,reason:string} */
    private function classificationWithVisualScore(array $classification, float $score, float $threshold): array
    {
        if ($score >= $threshold && $classification['status'] === 'missing_provenance') {
            return $this->classifiedAs(
                'visual_match_missing_provenance',
                'The image visually matches the reference, but its provenance is missing.',
            );
        }

        if ($score < $threshold && $classification['status'] === 'provenance_match') {
            return $this->classifiedAs(
                'provenance_match_visual_review',
                'The provenance matches, but the PHP visual score requires review.',
            );
        }

        if ($score < $threshold && $classification['status'] === 'missing_provenance') {
            return $this->classifiedAs(
                'likely_visual_mismatch',
                'The media lacks provenance and its PHP visual score is below the review threshold.',
            );
        }

        return $classification;
    }

    private function mediaHash(?Media $media): string
    {
        if (! $media instanceof Media || ! is_file($media->getPath())) {
            return '';
        }

        return hash_file('sha256', $media->getPath()) ?: '';
    }

    /** @return array{status:string,reason:string} */
    private function classifiedAs(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }

    /** @return array{score:null,image_url:null,error:null} */
    private function emptyVisualComparison(): array
    {
        return ['score' => null, 'image_url' => null, 'error' => null];
    }
}
