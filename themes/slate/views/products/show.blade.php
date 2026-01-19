<div class="container mt-14">
    <div class="bg-background-secondary border border-neutral/20 rounded-lg overflow-hidden shadow-sm">
        {{-- Header: Title + Stock --}}
        <!-- Header -->
        <div class="p-6 border-b border-neutral/20 flex items-center justify-between">
            <h1 class="text-3xl font-semibold">{{ $product->name }}</h1>

            @if ($product->stock === 0)
                <span class="inline-flex items-center gap-2 font-medium px-5 py-2.5 rounded-full "
                    style="background: rgba(185, 28, 28, 0.20);">
                    <span class="p-1.5 rounded-full" style="background: rgba(153, 27, 27, 0.40);">
                        <x-ri-error-warning-fill class="size-4" />
                    </span>
                    {{ __('product.out_of_stock', ['product' => $product->name]) }}
                </span>

            @else
                <span class="inline-flex items-center gap-2 font-medium px-5 py-2.5 rounded-full"
                    style="background: rgba(21, 128, 61, 0.20);">
                    <span class="p-1.5 rounded-full" style="background: rgba(22, 163, 74, 0.40);">
                        <x-ri-checkbox-circle-fill class="size-4" />
                    </span>
                    {{ __('product.in_stock') }}
                </span>

            @endif

        </div>

        <div class="p-6 space-y-6">
            {{-- Description --}}
            <div>
                <div class="prose dark:prose-invert max-w-none text-base/70">
                    {!! html_entity_decode((string) $product->description) !!}
                </div>
            </div>

            {{-- Price --}}
            <div class="bg-neutral-900/5 p-4 border-t border-gray-700">
                <div class="flex items-center justify-between">
                    <span class="text-xl font-medium">{{ __('invoices.price') }}</span>
                    <h2 class="text-2xl font-semibold">
                        {{ $product->price() }}
                    </h2>
                </div>
            </div>

            {{-- Add to cart --}}
            @if ($product->stock !== 0 && $product->price()->available)
                <div class="pt-2">
                    <a href="{{ route('products.checkout', ['category' => $category, 'product' => $product->slug]) }}"
                        wire:navigate class="block w-full">
                        <x-button.primary class="w-full flex items-center justify-center py-3">
                            <x-ri-shopping-cart-fill class="size-5 mr-2" />
                            {{ __('product.add_to_cart') }}
                        </x-button.primary>
                    </a>
                </div>
            @endif
        </div>
    </div>

    <div class="flex justify-start py-4">
        <a href="{{ route('category.show', ['category' => $category->slug]) }}" wire:navigate
            class="inline-flex items-center text-base/80 hover:text-primary transition-colors duration-200">
            <x-ri-arrow-left-line class="size-5 mr-1" />
            {{ __('Back to') }} {{ $category->name }}
        </a>
    </div>
</div>