<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;

class SocialLoginController extends Controller
{
    /**
     * Redirect to the OAuth provider for account linking.
     */
    public function link($provider)
    {
        return $this->initiateOAuth($provider, true);
    }

    /**
     * Redirect to the OAuth provider for login.
     */
    public function redirect($provider)
    {
        return $this->initiateOAuth($provider, false);
    }

    /**
     * Shared logic to initiate OAuth redirect.
     */
    private function initiateOAuth($provider, bool $isLinking)
    {
        // Allow Discord OAuth if either global OAuth is enabled OR Discord notifications are enabled
        if (!config("settings.oauth_$provider") && !($provider === 'discord' && config('settings.discord_notifications_enabled'))) {
            abort(404);
        }

        // Determine intent state
        $state = $isLinking ? 'LINK_ACCOUNT' : 'LOGIN';

        // Use the standard callback URL
        $callbackUrl = route('oauth.handle', $provider);

        // Build provider
        $driver = match ($provider) {
            'discord' => Socialite::buildProvider(DiscordProvider::class, [
                'client_id' => config('settings.oauth_discord_client_id'),
                'client_secret' => config('settings.oauth_discord_client_secret'),
                'redirect' => $callbackUrl,
            ]),
            'github' => Socialite::buildProvider(GithubProvider::class, [
                'client_id' => config('settings.oauth_github_client_id'),
                'client_secret' => config('settings.oauth_github_client_secret'),
                'redirect' => $callbackUrl,
            ]),
            'google' => Socialite::buildProvider(GoogleProvider::class, [
                'client_id' => config('settings.oauth_google_client_id'),
                'client_secret' => config('settings.oauth_google_client_secret'),
                'redirect' => $callbackUrl,
            ]),
            default => abort(404)
        };

        // Explicitly use stateless to avoid session blocking issues
        $driver->stateless();

        return match ($provider) {
            'discord' => $driver->scopes(['email'])->with(['state' => $state])->redirect(),
            'github' => $driver->scopes(['user:email'])->with(['state' => $state])->redirect(),
            'google' => $driver->scopes(['email'])->with(['state' => $state])->redirect(),
            default => abort(404)
        };
    }

    public function handle($provider)
    {
        // Check intent from state parameter
        $isLinking = request()->input('state') === 'LINK_ACCOUNT';

        // Build the provider with standard callback URL
        $callbackUrl = route('oauth.handle', $provider);

        if ($provider == 'discord') {
            $driver = Socialite::buildProvider(DiscordProvider::class, [
                'client_id' => config('settings.oauth_discord_client_id'),
                'client_secret' => config('settings.oauth_discord_client_secret'),
                'redirect' => $callbackUrl,
            ]);

            // Use stateless to avoid session dependency issues
            $oauth_user = $driver->stateless()->user();

            if ($oauth_user->user['verified'] == false) {
                return redirect()->route('login')->with('error', __('auth.oauth.unverified_discord_account'));
            }

            // Connection Logic
            // If we are linking (checked via state), OR if user is already logged in (session active)
            if ($isLinking || Auth::check()) {
                $currentUser = Auth::user();
                
                // Session Recovery: If session was lost but we know intent is linking,
                // try to find the user by email to restore the session.
                if (!$currentUser) {
                     $currentUser = User::where('email', $oauth_user->email)->first();
                }

                if ($currentUser) {
                    // Check if this discord account is already linked to another user
                    $existingUser = User::where('discord_user_id', $oauth_user->id)->first();
                    
                    if ($existingUser && $existingUser->id !== $currentUser->id) {
                        // Relogin to show error
                        Auth::login($currentUser);
                        return redirect()->route('account.notifications')->with('error', 'This Discord account is already connected to another user.');
                    }
                    
                    $currentUser->update(['discord_user_id' => $oauth_user->id]);

                    // Ensure logged in
                    Auth::login($currentUser);

                    Session::put('notification', [
                        'message' => __('account.discord_connected'),
                        'type' => 'success'
                    ]);

                    return redirect()->route('account.notifications');
                }
            }

            // Login Logic (if not linking)
            
            // 1. Try to find user by Discord ID
            $user = User::where('discord_user_id', $oauth_user->id)->first();

            // 2. If not found, try by email
            if (!$user) {
                $user = User::where('email', $oauth_user->email)->first();
                
                if ($user) {
                    // Start: Account Linking (Email Match)
                    if (!$user->discord_user_id) {
                        $user->update(['discord_user_id' => $oauth_user->id]);
                    }
                }
            }

            if (!$user) {
                return redirect()->route('register')->with('error', __('auth.oauth.account_not_registered'));
            }

            return $this->handleLogin($user, true, $isLinking);

        } elseif ($provider == 'google') {
            $driver = Socialite::buildProvider(GoogleProvider::class, [
                'client_id' => config('settings.oauth_google_client_id'),
                'client_secret' => config('settings.oauth_google_client_secret'),
                'redirect' => $callbackUrl,
            ]);

            $oauth_user = $driver->stateless()->user();

            $user = User::where('email', $oauth_user->email)->first();
            if (!$user) {
                return redirect()->route('register')->with('error', __('auth.oauth.account_not_registered'));
            }

            return $this->handleLogin($user, true, $isLinking);

        } elseif ($provider == 'github') {
            $driver = Socialite::buildProvider(GithubProvider::class, [
                'client_id' => config('settings.oauth_github_client_id'),
                'client_secret' => config('settings.oauth_github_client_secret'),
                'redirect' => $callbackUrl,
            ]);

            $oauth_user = $driver->stateless()->user();

            $user = User::where('email', $oauth_user->email)->first();
            if (!$user) {
                return redirect()->route('register')->with('error', __('auth.oauth.account_not_registered'));
            }

            return $this->handleLogin($user, true, $isLinking);

        } else {
            return redirect()->route('login');
        }
    }

    private function handleLogin(User $user, bool $remember, bool $isLinking = false)
    {
        Auth::login($user, $remember);

        // Check 2FA
        if (Auth::user()->tfa_secret) {
            // Store linking intent for after 2FA
            if ($isLinking) {
                Session::put('post_2fa_redirect', route('account.notifications'));
            }

            Session::put('2fa', [
                'user_id' => Auth::id(),
                'remember' => false,
                'expires' => now()->addMinutes(5),
            ]);

            Auth::logout();

            return redirect()->route('2fa');
        }

        // If this was account linking, redirect back to notifications
        if ($isLinking) {
            return redirect()->route('account.notifications');
        }

        return redirect()->route('home');
    }
}
