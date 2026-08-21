<x-filament-panels::page>
    <form wire:submit="send" class="min-w-0 space-y-6">
        {{ $this->form }}

        <div class="flex min-w-0 justify-stretch sm:justify-end">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" class="w-full sm:w-auto">
                Send Notification
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
