<div class="container mt-14">
    @if($invoice = $service->invoices()->where('status', 'pending')->first())
    <div class="w-full mb-4">
        <div class="bg-yellow-600/20 border-l-4 border-yellow-500 text-yellow-300 p-4 rounded-lg">
            <p class="font-medium">
                ⚠️ {{ __('services.outstanding_invoice') }}
                <a href="{{ route('invoices.show', $invoice)}}"
                    class="underline hover:text-yellow-100 underline-offset-2">{{ __('services.view_and_pay') }}</a>.
            </p>
        </div>
    </div>
    @endif
    <div class="bg-background-secondary border border-neutral p-6 rounded-lg mt-2">
        <div class="flex flex-col md:flex-row justify-between">
            <h1 class="text-2xl font-semibold">{{ __('services.services') }}</h1>
        </div>
        <div class="grid md:grid-cols-2 gap-4 my-4">
            <div>
                <h4 class="text-lg font-semibold">{{ __('services.product_details') }}:</h4>
                <div class="mt-2">
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.name') }}:</span>
                        <span class="text-base/50">{{ $service->product->name }}</span>
                    </div>
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.price') }}:</span>
                        <span class="text-base/50">{{ $service->formattedPrice }}</span>
                    </div>
                    @if($service->plan->type == 'recurring')
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.billing_cycle') }}:</span>
                        <span class="text-base/50">{{ __('services.every_period', [
                            'period' => $service->plan->billing_period > 1 ? $service->plan->billing_period : '',
                            'unit' => trans_choice(__('services.billing_cycles.' . $service->plan->billing_unit),
                            $service->plan->billing_period)
                            ])
                            }}</span>
                    </div>
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.expires_at') }}:</span>
                        <span class="text-base/50">{{ $service->expires_at ? $service->expires_at->format('M d, Y')
                            : 'N/A' }}</span>
                    </div>
                    @endif
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ __('services.status') }}:</span>
                        @if($service->cancellation && $service->status == 'active')
                        <span class="font-semibold text-orange-500">
                            {{ __('services.statuses.cancellation_pending') }}
                        </span>
                        @else
                        <span
                            class="font-semibold @if ($service->status == 'active') text-green-500 @elseif($service->status == 'cancelled') text-red-500  @else text-orange-500 @endif">
                            {{ __('services.statuses.' . $service->status) }}
                        </span>
                        @endif
                    </div>
                    @include('services.partials.billing-agreement')
                    <br>
                    @foreach ($fields as $field)
                    <div class="flex items-center text-base">
                        <span class="mr-2">{{ $field['label'] }}:</span>
                        <span class="text-base/50">{{ $field['text'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @if($service->cancellable || $service->upgradable || count($buttons) > 0)
            <div>
                <h4 class="text-lg font-semibold">{{ __('services.actions') }}:</h4>
                <div class="mt-2 flex flex-row gap-2 flex-wrap">
                    @if($service->status == 'active' && $service->plan->type == 'recurring')
                        <x-button.secondary class="h-fit !w-fit" wire:click="$set('showGenerateInvoice', true)">
                            <span wire:loading.remove wire:target="$set('showGenerateInvoice', true)">{{ __('services.generate_invoice') }}</span>
                            <x-loading target="$set('showGenerateInvoice', true)" />
                        </x-button.secondary>
                    @endif
                    @if($service->upgradable)
                    <a href="{{ route('services.upgrade', $service->id) }}">
                        <x-button.primary class="h-fit !w-fit">
                            <span>{{ __('services.upgrade') }}</span>
                        </x-button.primary>
                    </a>
                    @endif
                    @if($service->upgrade()->where('status', 'pending')->exists())
                    <x-button.primary class="h-fit !w-fit" wire:click="$set('showCancelUpgrade', true)">
                        <span wire:loading.remove wire:target="$set('showCancelUpgrade', true)">
                            {{ __('services.cancel_upgrade') }}
                        </span>
                        <x-loading target="$set('showCancelUpgrade', true)" />
                    </x-button.primary>
                    @endif
                    @if($service->cancellable)
                    <x-button.danger class="h-fit !w-fit" wire:click="$set('showCancel', true)">
                        <span wire:loading.remove wire:target="$set('showCancel', true)">{{ __('services.cancel')
                            }}</span>
                        <x-loading target="$set('showCancel', true)" />
                    </x-button.danger>
                    @endif
                    @if($showCancel)
                    <x-modal open="true"
                        title="{{ __('services.cancellation', ['service' => $service->product->name]) }}"
                        width="max-w-3xl">
                        <livewire:services.cancel :service="$service" />
                        <x-slot name="closeTrigger">
                            <div class="flex gap-4">
                                <button wire:click="$set('showCancel', false)" @click="open = false"
                                    class="text-primary-100">
                                    <x-ri-close-fill class="size-6" />
                                </button>
                            </div>
                        </x-slot>
                    </x-modal>
                    @endif
                    @if($showCancelUpgrade)
                        <x-modal open="true"
                            title="{{ __('services.cancel_upgrade', ['service' => $service->product->name]) }}"
                            width="max-w-3xl">
                            <livewire:services.cancel-upgrade :service="$service" />
                            <x-slot name="closeTrigger">
                                <div class="flex gap-4">
                                    <button wire:click="$set('showCancelUpgrade', false)" @click="open = false"
                                        class="text-primary-100">
                                        <x-ri-close-fill class="size-6" />
                                    </button>
                                </div>
                            </x-slot>
                        </x-modal>
                    @endif
                    @if($showGenerateInvoice)
                        <x-modal open="true" title="{{ __('services.generate_invoice') }}" width="max-w-md">
                            <div class="p-6">
                                <p class="text-base/70 mb-4">{{ __('services.extend_service') }} - {{ $service->product->name }}</p>
                                
                                <div class="mb-4">
                                    <label for="invoice-months" class="block text-sm font-medium mb-2">{{ __('services.select_duration') }}</label>
                                    <select wire:model="selectedMonths" id="invoice-months" class="w-full px-4 py-2 bg-background-secondary border border-neutral rounded-lg focus:ring-2 focus:ring-primary focus:border-primary transition-colors">
                                        <option value="">{{ __('services.select_months') }}</option>
                                        <option value="1">1 {{ __('services.month') }}</option>
                                        <option value="3">3 {{ __('services.months') }}</option>
                                        <option value="6">6 {{ __('services.months') }}</option>
                                        <option value="9">9 {{ __('services.months') }}</option>
                                        <option value="12">12 {{ __('services.months') }}</option>
                                    </select>
                                </div>

                                <div class="flex gap-3">
                                    <button wire:click="generateInvoice" class="flex-1 px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!$wire.selectedMonths">
                                        <span wire:loading.remove wire:target="generateInvoice">{{ __('services.generate') }}</span>
                                        <span wire:loading wire:target="generateInvoice">{{ __('services.generating') }}...</span>
                                    </button>
                                    <button wire:click="$set('showGenerateInvoice', false)" @click="open = false" class="px-4 py-2 bg-background-secondary hover:bg-background-tertiary border border-neutral text-base rounded-lg transition-colors">
                                        {{ __('services.cancel') }}
                                    </button>
                                </div>
                            </div>
                            <x-slot name="closeTrigger">
                                <div class="flex gap-4">
                                    <button wire:click="$set('showGenerateInvoice', false)" @click="open = false" class="text-primary-100">
                                        <x-ri-close-fill class="size-6" />
                                    </button>
                                </div>
                            </x-slot>
                        </x-modal>
                    @endif
                    
                    @if($showAutoCancelModal)
                        <x-modal open="true" title="Pending Invoice Found" width="max-w-md">
                            <div class="p-6">
                                <div class="flex items-start mb-4">
                                    <div class="flex-shrink-0">
                                        <x-ri-error-warning-line class="h-6 w-6 text-yellow-500" />
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-base/70">
                                            A pending service extension invoice already exists for this service.
                                        </p>
                                        <p class="text-base/70 mt-2">
                                            <a href="{{ route('invoices.show', $pendingInvoiceId) }}" class="text-primary underline" target="_blank">
                                                View existing invoice
                                            </a>
                                        </p>
                                        <p class="text-base/70 mt-3">
                                            Would you like to cancel the existing invoice and create a new one?
                                        </p>
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <button wire:click="confirmGenerateInvoice" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                                        <span wire:loading.remove wire:target="confirmGenerateInvoice">Cancel & Generate</span>
                                        <span wire:loading wire:target="confirmGenerateInvoice">Processing...</span>
                                    </button>
                                    <button wire:click="proceedWithoutCancel" @click="open = false" class="px-4 py-2 bg-background-secondary hover:bg-background-tertiary border border-neutral text-base rounded-lg transition-colors">
                                        Keep Existing
                                    </button>
                                </div>
                            </div>
                            <x-slot name="closeTrigger">
                                <div class="flex gap-4">
                                    <button wire:click="proceedWithoutCancel" @click="open = false" class="text-primary-100">
                                        <x-ri-close-fill class="size-6" />
                                    </button>
                                </div>
                            </x-slot>
                        </x-modal>
                    @endif
                </div>
                <div class="mt-2 flex flex-row gap-2 flex-wrap">
                    @foreach ($buttons as $button)
                    <!-- If the button has a function then call it when clicked -->
                    @if (isset($button['function']))
                    <x-button.primary class="h-fit !w-fit" wire:click="goto('{{ $button['function'] }}')">
                        {{ $button['label'] }}
                    </x-button.primary>
                    @else
                    <a href="{{ $button['url'] }}"
                        @if(!empty($button['target'])) target="{{ $button['target'] }}" @endif
                        @if(($button['target'] ?? null) === '_blank') rel="noopener noreferrer" @endif>
                        <x-button.primary class="h-fit !w-fit">
                            {{ $button['label'] }}
                        </x-button.primary>
                    </a>
                    @endif
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>

    @if (count($views) > 0)
    <div class="bg-primary-800 rounded-lg mt-2">
        @if (count($views) > 1)
        <div class="flex w-fit mb-2 flex-row flex-wrap">
            @foreach ($views as $view)
            <button wire:click="changeView('{{ $view['name'] }}')"
                class="px-4 py-2 -mb-px focus:outline-none {{ $view['name'] == $currentView ? 'border-b-2 border-gray-400 font-semibold' : 'text-base border-b border-gray-500 ' }}">
                {{ $view['label'] }}
            </button>
            @endforeach
        </div>
        @endif

        <!-- show loading spinner -->
        <x-loading target="changeView" />
        <div wire:loading.remove wire:target="changeView">
            {!! $extensionView !!}
        </div>
    </div>
    @endif
</div>