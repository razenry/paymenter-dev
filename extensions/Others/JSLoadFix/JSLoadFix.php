<?php

namespace Paymenter\Extensions\Others\JSLoadFix;

use App\Classes\Extension\Extension;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;

#[ExtensionMeta(
    name: 'JSLoadFix',
    description: 'Fixes JS loading logic so other scripts can load properly, using a timed reload guard.',
    version: '1.3',
    author: 'QKingsoftware'
)]
class JSLoadFix extends Extension
{
    public function getName(): string
    {
        return 'JS Load Fix';
    }

    public function getDescription(): string
    {
        return 'Prevents broken JS by triggering a controlled reload when needed, with a per-page session guard.';
    }

    public function getVersion(): string
    {
        return '1.3';
    }

    public function getAuthor(): string
    {
        return 'QKingsoftware';
    }

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'failsafe_ms',
                'type' => 'number',
                'label' => 'Failsafe Time (ms)',
                'description' => 'Minimum delay before reload can happen again. Default: 1000 ms.',
                'required' => true,
                'value' => $values['failsafe_ms'] ?? 1000,
            ],
            [
                'name' => 'paths',
                'type' => 'text',
                'label' => 'Apply to Pages (comma-separated)',
                'description' => 'List URL path fragments to apply this fix on (e.g. /clientarea, /dashboard). Leave blank for all pages.',
                'required' => false,
                'value' => $values['paths'] ?? '',
            ],
        ];
    }

    public function boot(): void
    {
        if (!request()->isMethod('GET')) {
            return;
        }

        $paths = array_filter(array_map('trim', explode(',', (string)$this->config('paths', ''))));
        $currentPath = '/' . ltrim(request()->path(), '/');

        if (!empty($paths)) {
            $shouldApply = false;
            foreach ($paths as $p) {
                if (stripos($currentPath, $p) !== false) {
                    $shouldApply = true;
                    break;
                }
            }
            if (!$shouldApply) {
                return;
            }
        }

        $failsafe = intval($this->config('failsafe_ms', 1000));
        $bodyScript = $this->getBodyScript($failsafe);

        Event::listen('body', function () use ($bodyScript) {
            return ['view' => new HtmlString($bodyScript)];
        });
    }

    private function getBodyScript(int $failsafe): string
    {
        $expiry = 30 * 1000;

        return <<<HTML
<script>
(function() {
    const path = location.pathname || "default";
    const keyBase = "jsReloadFix_";
    const timeKey = keyBase + path + "_time";
    const doneKey = keyBase + path + "_done";

    const lastReload = parseInt(sessionStorage.getItem(timeKey) || "0");
    const hasReloaded = sessionStorage.getItem(doneKey) === "1";
    const now = Date.now();

    if (lastReload && (now - lastReload) > {$expiry}) {
        sessionStorage.removeItem(timeKey);
        sessionStorage.removeItem(doneKey);
    }

    const freshReload = !sessionStorage.getItem(doneKey);
    if (freshReload && (now - lastReload) > {$failsafe}) {
        sessionStorage.setItem(timeKey, now);
        sessionStorage.setItem(doneKey, "1");
        setTimeout(() => location.reload(), 50);
    }
})();
</script>
HTML;
    }

    public function enabled(): void {}
    public function disabled(): void {}
    public function updated(): void {}
    public function install(): void {}
}
