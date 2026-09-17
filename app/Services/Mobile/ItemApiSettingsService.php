<?php

namespace App\Services\Mobile;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class ItemApiSettingsService
{
    public const string UNIQUE_SERIAL_ITEMS_KEY = 'api_unique_serial_items';

    public const bool DEFAULT_UNIQUE_SERIAL_ITEMS = false;

    private ?bool $uniqueSerialItems = null;

    public function uniqueSerialItemsEnabled(): bool
    {
        if ($this->uniqueSerialItems !== null) {
            return $this->uniqueSerialItems;
        }

        if (! Schema::hasTable('settings')) {
            return $this->uniqueSerialItems = self::DEFAULT_UNIQUE_SERIAL_ITEMS;
        }

        $storedValue = Setting::query()
            ->whereKey(self::UNIQUE_SERIAL_ITEMS_KEY)
            ->value('value');

        return $this->uniqueSerialItems = $this->normalizeBoolean(
            $storedValue ?? self::DEFAULT_UNIQUE_SERIAL_ITEMS,
        );
    }

    public function updateUniqueSerialItems(bool $enabled): bool
    {
        Setting::query()->updateOrCreate(
            ['key' => self::UNIQUE_SERIAL_ITEMS_KEY],
            ['value' => $enabled ? '1' : '0'],
        );

        return $this->uniqueSerialItems = $enabled;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
