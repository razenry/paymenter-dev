<footer class="w-full py-6 border-t border-neutral/10 bg-background-secondary mt-2">
    <div class="max-w-7xl mx-auto px-6 lg:px-8">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="text-sm text-base/70 mb-4 md:mb-0">
                {{ __('© :year :app_name. All Rights Reserved.', ['year' => date('Y'), 'app_name' => config('app.name')]) }}
            </div>

        </div>
    </div>
</footer>
