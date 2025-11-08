<div class="max-w-7xl mx-auto">



    @if (theme('enable_features_section', true))
        <div class="mb-16">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-4xl font-bold text-base mb-4">
                    {{ theme('features_title', 'Why Choose Our Hosting?') }}</h2>
                <p class="text-lg text-base/70 max-w-2xl mx-auto">
                    {{ theme('features_subtitle', 'We provide everything you need to succeed online with reliable, fast, and secure hosting solutions.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @php
                    $features = [
                        [
                            'title' => theme('feature_1_title', 'Lightning Fast'),
                            'description' => theme(
                                'feature_1_description',
                                'SSD storage, CDN integration, and optimized servers ensure your website loads in milliseconds.',
                            ),
                            'color' => 'primary',
                            'icon' => theme('feature_1_icon', 'ri-check-line'),
                        ],
                        [
                            'title' => theme('feature_2_title', 'Bank-Level Security'),
                            'description' => theme(
                                'feature_2_description',
                                'Advanced security measures protect your data with SSL certificates, firewalls, and daily backups.',
                            ),
                            'color' => 'success',
                            'icon' => theme('feature_2_icon', 'ri-shield-keyhole-line'),
                        ],
                        [
                            'title' => theme('feature_3_title', 'Expert Support 24/7'),
                            'description' => theme(
                                'feature_3_description',
                                'Our hosting experts are available around the clock to help with any technical issues or questions.',
                            ),
                            'color' => 'warning',
                            'icon' => theme('feature_3_icon', 'ri-customer-service-2-line'),
                        ],
                        [
                            'title' => theme('feature_4_title', 'Easily Scalable'),
                            'description' => theme(
                                'feature_4_description',
                                'Start small and grow big. Upgrade your resources instantly as your business expands.',
                            ),
                            'color' => 'info',
                            'icon' => theme('feature_4_icon', 'ri-stack-line'),
                        ],
                        [
                            'title' => theme('feature_5_title', 'User-Friendly Control Panel'),
                            'description' => theme(
                                'feature_5_description',
                                'Manage your hosting with our intuitive control panel. No technical knowledge required.',
                            ),
                            'color' => 'secondary',
                            'icon' => theme('feature_5_icon', 'ri-dashboard-3-line'),
                        ],
                        [
                            'title' => theme('feature_6_title', '99.9% Uptime Guarantee'),
                            'description' => theme(
                                'feature_6_description',
                                'Your website stays online with our reliable infrastructure and uptime monitoring.',
                            ),
                            'color' => 'error',
                            'icon' => theme('feature_6_icon', 'ri-timer-line'),
                        ],
                    ];
                @endphp

                @foreach ($features as $feature)
                    @if ($feature['title'])
                        @php
                            $iconComponent = $feature['icon'] ?? null;
                            if (!empty($iconComponent) && !Str::startsWith($iconComponent, 'ri-')) {
                                $iconComponent = Str::start($iconComponent, 'ri-');
                            }
                        @endphp
                        <div
                            class="bg-background-secondary border border-neutral/20 rounded-xl p-6 hover:border-neutral/30 transition-all duration-200 hover:shadow-sm">
                            <div class="bg-{{ $feature['color'] }}/10 p-3 rounded-lg w-fit mb-4">
                                @if (!empty($iconComponent))
                                    <x-dynamic-component :component="$iconComponent"
                                        class="size-8 text-{{ $feature['color'] }}" />
                                @endif
                            </div>
                            <h3 class="text-xl font-semibold mb-3">{{ $feature['title'] }}</h3>
                            <p class="text-base/70 mb-4">{{ $feature['description'] }}</p>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    <div class="w-full">
        <x-panel-showcase />
    </div>

    <div id="services" class="mb-16">
        <div class="text-center mb-12">
            <h2 class="text-3xl md:text-4xl font-bold text-base mb-4">
                {{ theme('services_title', 'Choose Our Hosting Services') }}
            </h2>
            <p class="text-lg text-base/70 max-w-2xl mx-auto">
                {{ theme('services_subtitle', 'Explore our range of specialized hosting services designed to meet your specific needs.') }}
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($categories as $category)
                <a href="{{ route('category.show', ['category' => $category->slug]) }}"
                    class="group bg-background-secondary border border-neutral/20 rounded-xl overflow-hidden hover:border-neutral/30 transition-all duration-200 hover:shadow-lg"
                    wire:navigate>
                    @if (theme('small_images', false))
                        <div class="p-6">
                            <div class="flex gap-x-3 items-center mb-3">
                                @if ($category->image)
                                    <img src="{{ Storage::url($category->image) }}" alt="{{ $category->name }}"
                                        class="w-14 h-14 object-cover rounded-md">
                                @else
                                    <div class="w-14 h-14 bg-neutral/10 flex items-center justify-center rounded-md">
                                        <x-ri-server-line class="size-6 text-base/30" />
                                    </div>
                                @endif
                                <h3 class="text-xl font-semibold group-hover:text-primary transition-colors">
                                    {{ $category->name }}</h3>
                            </div>
                        @else
                            @if ($category->image)
                                <div class="aspect-[16/10] overflow-hidden bg-neutral/10">
                                    <img src="{{ Storage::url($category->image) }}" alt="{{ $category->name }}"
                                        class="w-full h-full object-cover object-center transition-transform duration-500 group-hover:scale-105">
                                </div>
                            @else
                                <div
                                    class="aspect-[16/10] bg-gradient-to-br from-primary/10 to-primary/5 flex items-center justify-center">
                                    <x-ri-server-line class="size-16 text-primary/30" />
                                </div>
                            @endif

                            <div class="p-6">
                                <h3 class="text-xl font-semibold mb-3 group-hover:text-primary transition-colors">
                                    {{ $category->name }}</h3>
                    @endif

                    @if (theme('show_category_description', true))
                        <div class="text-base/70 mb-4 line-clamp-3">
                            {!! strip_tags($category->description) !!}
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-primary">{{ __('View Service') }}</span>
                        <x-ri-arrow-right-s-line
                            class="size-5 text-primary group-hover:translate-x-1 transition-transform" />
                    </div>
        </div>
        </a>
        @endforeach
    </div>
</div>

{!! hook('pages.home') !!}
</div>
