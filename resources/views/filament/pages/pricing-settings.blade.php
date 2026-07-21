<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Save pricing settings
            </x-filament::button>
        </div>
    </form>

    @php
        $previewRatePercent = $this->getPreviewRatePercent();
        $previewRows = $this->getPricePreviewRows();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Price change preview</x-slot>
        <x-slot name="description">
            Compare a sample of API-visible items using the saved {{ number_format($this->savedRatePercent, 2) }}% rate and the unsaved {{ number_format($previewRatePercent, 2) }}% rate.
        </x-slot>

        @if ($previewRows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                No API-visible items are available for preview. Add complete item pricing data and an image to at least one item.
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-3">Item code</th>
                            <th class="px-4 py-3">Model</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3 text-right">Current price</th>
                            <th class="px-4 py-3 text-right">Preview price</th>
                            <th class="px-4 py-3 text-right">Change</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                        @foreach ($previewRows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">
                                    {{ $row['serial_code'] }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['model'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['group'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-700 dark:text-gray-300">
                                    ${{ number_format($row['current_price'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-gray-950 dark:text-white">
                                    ${{ number_format($row['preview_price'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-gray-700 dark:text-gray-300">
                                    {{ $row['difference'] >= 0 ? '+' : '' }}${{ number_format($row['difference'], 2) }}
                                    ({{ $row['change_percent'] >= 0 ? '+' : '' }}{{ number_format($row['change_percent'], 2) }}%)
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
