<div class="container mt-14">

    @if(auth()->check() && !auth()->user()->hasVerifiedEmail())
        <div class="mb-6">
            <div class="flex flex-col gap-4 rounded-xl border border-neutral bg-background-secondary p-4
                           sm:flex-row sm:items-center sm:justify-between">

                <div class="flex items-start gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-neutral bg-background">
                        <x-ri-mail-line class="size-5 text-primary" />
                    </div>

                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            Email not verified
                        </p>
                        <p class="text-sm text-base/60 leading-snug">
                            Please verify your email address to avoid service interruption.
                        </p>
                    </div>
                </div>

                <a href="{{ route('verification.notice') }}" class="inline-flex w-full items-center justify-center rounded-lg border border-neutral
                              bg-background px-4 py-2 text-sm font-semibold
                              hover:bg-background-secondary
                              sm:w-auto">
                    Verify email
                </a>
            </div>
        </div>
    @endif

    <x-navigation.breadcrumb />
    <p class="text-base text-base/60 font mt-2 mb-8">
        {{ __('dashboard.dashboard_description') }}
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mt-8 items-start">

        <div class="grid gap-8 items-start">
            <!-- Active Services -->
            <div class="">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="bg-background-secondary border border-neutral p-2 rounded-lg">
                            <x-ri-archive-stack-fill class="size-5" />
                        </div>
                        <h2 class="text-xl font-semibold">{{ __('dashboard.active_services') }}</h2>
                    </div>
                    <span
                        class="bg-primary flex items-center justify-center font-semibold rounded-md size-5 text-sm text-white">
                        {{ Auth::user()->services()->where('status', 'active')->count() }}
                    </span>
                </div>
                <div class="space-y-4">
                    <livewire:services.widget status="active" />
                </div>
                <x-navigation.link
                    class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral flex items-center justify-center rounded-lg"
                    :href="route('services')">
                    {{ __('dashboard.view_all') }}
                    <x-ri-arrow-right-fill class="size-5" />
                </x-navigation.link>
            </div>

            <!-- Open Tickets -->
            @if(!config('settings.tickets_disabled', false))
                <div class="">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-3">
                            <div class="bg-background-secondary border border-neutral p-2 rounded-lg">
                                <x-ri-customer-service-fill class="size-5" />

                            </div>
                            <h2 class="text-xl font-semibold">{{ __('dashboard.open_tickets') }}</h2>
                            <a href="{{ route('tickets.create') }}" wire:navigate>
                                <x-ri-add-fill class="size-5 h-5" />
                            </a>
                        </div>
                        <span
                            class="bg-primary flex items-center justify-center font-semibold rounded-md size-5 text-sm text-white">
                            {{ Auth::user()->tickets()->where('status', '!=', 'closed')->count() }}
                        </span>
                    </div>
                    <div class="space-y-4">
                        <livewire:tickets.widget />
                    </div>
                    <x-navigation.link
                        class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral flex items-center justify-center rounded-lg"
                        :href="route('tickets')">
                        {{ __('dashboard.view_all') }}
                        <x-ri-arrow-right-fill class="size-5 h-5" />
                    </x-navigation.link>
                </div>
            @endif
        </div>

        <div class="grid gap-8 items-start">
            <!-- Unpaid Invoices -->
            <div class="">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="bg-background-secondary border border-neutral p-2 rounded-lg">
                            <x-ri-receipt-fill class="size-5" />
                        </div>
                        <h2 class="text-xl font-semibold">{{ __('dashboard.unpaid_invoices') }}</h2>
                    </div>
                    <span
                        class="bg-primary flex items-center justify-center font-semibold rounded-md size-5 text-sm text-white">
                        {{ Auth::user()->invoices()->where('status', 'pending')->count() }}
                    </span>
                </div>
                <div class="space-y-4">
                    <livewire:invoices.widget :limit="3" />
                </div>
                <x-navigation.link
                    class="bg-background-secondary hover:bg-background-secondary/80 border border-neutral flex items-center justify-center rounded-lg"
                    :href="route('invoices')">
                    {{ __('dashboard.view_all') }}
                    <x-ri-arrow-right-fill class="size-5 h-5" />
                </x-navigation.link>
            </div>
            {!! hook('pages.dashboard') !!}
        </div>
    </div>
</div>