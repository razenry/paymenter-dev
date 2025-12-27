<div>
    <p>
        {{ __('services.cancel_upgrade_confirmation', [
            'to' => $pendingUpgrade->product->name,
            'from' => $service->product->name,
        ]) }}
    </p>


    <div class="mt-4 flex gap-2">
        <x-button.danger wire:click="cancelUpgrade">
            {{ __('services.yes_cancel_upgrade') }}
        </x-button.danger>
        <x-button.secondary @click="$dispatch('closeModal')">
            {{ __('services.close') }}
        </x-button.secondary>
    </div>
</div>