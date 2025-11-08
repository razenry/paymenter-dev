<div class="min-h-[80vh] flex items-center justify-center w-full py-4">
    <form
        class="mx-auto flex flex-col gap-6 shadow-lg px-6 sm:px-8 py-8 bg-background-secondary backdrop-blur-sm rounded-lg max-w-2xl w-full border border-neutral/20"
        wire:submit.prevent="submit" id="register">

        <!-- Header -->
        <div class="flex flex-col items-center mb-6">
            <x-logo class="h-10 mb-2" />
            <h1 class="text-2xl font-bold text-center text-base">{{ __('auth.sign_up_title') }}</h1>
        </div>


        <!-- Account Credentials Section -->
        <div class="border-b border-neutral/20 pb-4">
            <h2 class="text-lg font-semibold mb-3">{{ __('account.personal_details') }}</h2>
            <div class="flex flex-col md:grid md:grid-cols-2 gap-3">
                <x-form.input name="first_name" type="text" :label="__('general.input.first_name')" :placeholder="__('general.input.first_name_placeholder')" wire:model="first_name"
                    required divClass="mt-1" />
                <x-form.input name="last_name" type="text" :label="__('general.input.last_name')" :placeholder="__('general.input.last_name_placeholder')" wire:model="last_name"
                    required divClass="mt-1" />
            </div>
            <div class="flex flex-col gap-3">
                <x-form.input name="email" type="email" :label="__('general.input.email')" :placeholder="__('general.input.email_placeholder')" required wire:model="email"
                    divClass="mt-1" />
                <x-form.input name="password" type="password" :label="__('Password')" :placeholder="__('Your password')" wire:model="password"
                    required divClass="mt-1" />
                <x-form.input name="password_confirm" type="password" :label="__('Password')" :placeholder="__('Confirm your password')"
                    wire:model="password_confirmation" required divClass="mt-1" />
            </div>
        </div>

        <!-- Custom Properties Section -->
        @if($custom_properties || $properties)
        <div class="border-b border-neutral/20 pb-4">
            <x-form.properties :custom_properties="$custom_properties" :properties="$properties" />
        </div>
        @endif

        <!-- Terms of Service -->
        @if (config('settings.tos'))
        <div class="pb-4">
            <x-form.checkbox wire:model="tos" name="tos" required>
                {{ __('product.tos') }}
                <a href="{{ config('settings.tos') }}" target="_blank"
                    class="text-primary hover:underline transition-colors">
                    {{ __('product.tos_link') }}
                </a>
            </x-form.checkbox>
        </div>
        @endif

        <!-- CAPTCHA -->
        <div class="pb-4">
            <x-captcha :form="'register'" />
        </div>

        <!-- Submit Button -->
        <x-button.primary
            class="w-full py-2 mt-3 font-medium transition-all duration-200 hover:shadow-lg hover:shadow-primary/20"
            type="submit">
            {{ __('Sign up') }}
        </x-button.primary>

        <!-- Footer Links -->
        <div class="text-center mt-4 text-xs text-base/70">
            {{ __('auth.already_have_account') }}
            <a class="text-primary font-medium hover:underline transition-colors" href="{{ route('login') }}"
                wire:navigate>
                {{ __('auth.sign_in') }}
            </a>
        </div>
    </form>
</div>
