<div>
    <p>Are you sure you want to cancel the pending upgrade for {{ $pendingUpgrade->product->name }}?</p>

    <div class="mt-4 flex gap-2">
        <x-button.danger wire:click="cancelUpgrade">
            Yes, cancel upgrade
        </x-button.danger>
        <x-button.secondary @click="$dispatch('closeModal')">
            Close
        </x-button.secondary>
    </div>
</div>
