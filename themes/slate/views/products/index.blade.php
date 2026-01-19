<div class="container mt-14">
    <div class="flex flex-col md:grid md:grid-cols-4 gap-4">
        <div class="flex flex-col gap-2">
            <div>
                <h1 class="text-2xl font-bold mb-2">{{ $category->name }}</h1>
                <article class="prose dark:prose-invert text-base/70">
                    {!! $category->description !!}
                </article>
            </div>
            <div
                class="flex flex-col bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg">
                @foreach ($categories as $ccategory)
                    <a href="{{ route('category.show', ['category' => $ccategory->slug]) }}" wire:navigate
                        class="flex items-center px-3 py-2 rounded-md transition-colors duration-200 hover:bg-neutral/10 {{ $category->id == $ccategory->id ? 'bg-neutral/10 text-primary font-medium' : 'text-base/80' }}">
                        <x-ri-folder-line
                            class="size-4 mr-2 {{ $category->id == $ccategory->id ? 'text-primary' : 'text-base/60' }}" />
                        {{ $ccategory->name }}
                    </a>
                @endforeach
            </div>
        </div>
        <div class="flex flex-col gap-6 col-span-3">
            @if (count($childCategories) >= 1)
                <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-4 h-fit">
                    @foreach ($childCategories as $childCategory)
                        <div
                            class="flex flex-col bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg">
                            @if(theme('small_images', false))
                                <div class="flex gap-x-3 items-center">
                            @endif
                                @if ($childCategory->image)
                                    <img src="{{ Storage::url($childCategory->image) }}" alt="{{ $childCategory->name }}"
                                        class="rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'w-full object-cover object-center' }}">
                                @endif
                                <h2 class="text-xl font-bold">{{ $childCategory->name }}</h2>
                                @if(theme('small_images', false))
                                    </div>
                                @endif
                            @if(theme('show_category_description', true))
                                <article class="mt-2 prose dark:prose-invert">
                                    {!! $childCategory->description !!}
                                </article>
                            @endif
                            <a href="{{ route('category.show', ['category' => $childCategory->slug]) }}" wire:navigate
                                class="mt-2">
                                <x-button.primary>
                                    {{ __('common.button.view') }}
                                </x-button.primary>
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-4 h-fit">
                @foreach ($products as $product)
                    <div
                        class="flex flex-col bg-background-secondary hover:bg-background-secondary/80 border border-neutral p-4 rounded-lg">
                        @if(theme('small_images', false))
                            <div class="flex gap-x-3 items-center">
                        @endif
                            @if ($product->image)
                                <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
                                    class="rounded-md {{ theme('small_images', false) ? 'w-14 h-fit' : 'w-full object-cover object-center' }}">
                            @endif
                            <h2 class="text-xl font-bold">{{ $product->name }}</h2>
                            @if(theme('small_images', false))
                                </div>
                            @endif
                        @if(theme('direct_checkout', false) && $product->description)
                            <div class="my-6 text-sm text-base/70">
                                {!! html_entity_decode((string) $product->description) !!}
                            </div>
                        @endif
                        <div class="mb-3.5 text-center">
                            <h3 class="text-md font-bold">
                                {{ $product->price()->formatted->price }}
                            </h3>
                        </div>
                        <div class="mt-auto pt-2 flex items-center gap-2">
                            @if (($product->stock > 0 || !$product->stock) && $product->price()->available && theme('direct_checkout', false))
                                <a href="{{ route('products.checkout', ['category' => $category, 'product' => $product->slug]) }}"
                                    class="block w-full" wire:navigate>
                                    <x-button.primary class="w-full justify-center">
                                        <x-ri-shopping-cart-fill class="size-4 mr-2" />

                                        {{ __('product.add_to_cart') }}
                                    </x-button.primary>
                                </a>
                            @else
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('products.show', ['category' => $product->category, 'product' => $product->slug]) }}"
                                        class="flex-grow" wire:navigate>
                                        <x-button.primary class="w-full justify-center">
                                            <x-ri-eye-fill class="size-4 mr-2" />
                                            {{ __('common.button.view') }}
                                        </x-button.primary>
                                    </a>
                                    @if ($product->stock !== 0 && $product->price()->available)
                                        <a href="{{ route('products.checkout', ['category' => $category, 'product' => $product->slug]) }}"
                                            wire:navigate>
                                            <x-button.secondary>
                                                <x-ri-shopping-bag-4-fill class="size-5" />
                                            </x-button.secondary>
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>