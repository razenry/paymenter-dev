<div class="flex flex-col space-y-6">
    <x-navigation.breadcrumb />

    <div class="bg-background-secondary border border-neutral/20 rounded-lg overflow-hidden shadow-sm">
        {{-- Header: Title + Stock --}}
        <div class="border-b border-neutral/20 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <h1 class="text-2xl font-bold">{{ $product->name }}</h1>

            @if ($product->stock === 0)
                <span
                    class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium bg-error/10 text-error border border-error/20">
                    <x-ri-error-warning-fill class="size-4 mr-1.5" />
                    {{ __('product.out_of_stock', ['product' => $product->name]) }}
                </span>
            @elseif($product->stock > 0)
                <span
                    class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium bg-success/10 text-success border border-success/20">
                    <x-ri-checkbox-circle-fill class="size-4 mr-1.5" />
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
            <div class="bg-neutral/5 p-4 rounded-lg border border-neutral/10">
                <div class="flex items-center justify-between">
                    <span class="text-base/60 font-medium">{{ __('invoices.price') }}</span>
                    <h2 class="text-2xl font-semibold text-primary">
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

    <div class="flex justify-start">
        <a href="{{ route('category.show', ['category' => $category->slug]) }}" wire:navigate
            class="inline-flex items-center text-base/80 hover:text-primary transition-colors duration-200">
            <x-ri-arrow-left-line class="size-5 mr-1" />
            {{ __('Back to') }} {{ $category->name }}
        </a>
    </div>
</div>
