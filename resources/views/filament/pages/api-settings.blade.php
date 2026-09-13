<x-filament-panels::page>
    <form wire:submit="save" class="min-w-0 space-y-6">
        {{ $this->form }}

        <div class="flex min-w-0 justify-stretch sm:justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check" class="w-full sm:w-auto">
                Save API settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
