<?php

namespace App\Livewire\Auth;

use App\Helpers\NotificationHelper;
use App\Livewire\Component;
use App\Traits\Captchable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class VerifyEmail extends Component
{
    use Captchable;

    public function mount()
    {
        if (Auth::user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }
    }

    public function submit()
    {
        $this->captcha();

        $key = 'email-verification:' . Auth::id();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->addError('code', 'Too many attempts. Try again later.');

            return;
        }

        NotificationHelper::emailVerificationNotification(Auth::user());

        RateLimiter::hit($key, 120);
        session()->flash('email_verification_sent', true);
    }

    public function render()
    {
        return view('auth.verify-email');
    }
}
