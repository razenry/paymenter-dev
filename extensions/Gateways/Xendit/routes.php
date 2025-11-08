<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Xendit\Xendit;

Route::post('/extensions/xendit/webhook', [Xendit::class, 'webhook'])->withoutMiddleware([VerifyCsrfToken::class])->name('extensions.gateways.xendit.webhook');
