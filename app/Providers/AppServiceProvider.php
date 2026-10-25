<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use App\Policies\InquiryPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\UserPolicy;
use App\Support\AccountEligibility;
use App\Support\CompatiblePasswordHasher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Hash::extend('horizon', fn ($app): CompatiblePasswordHasher => new CompatiblePasswordHasher(
            $app['config']['hashing.argon'], $app['config']['hashing.bcrypt'],
        ));
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(Inquiry::class, InquiryPolicy::class);

        RateLimiter::for('registration', function (Request $request): array {
            $email = $request->input('email');
            $canonical = is_string($email) ? strtolower(trim($email, " \t\r\n")) : 'invalid';

            return [
                Limit::perHour(5)->by('registration-ip:'.$request->ip()),
                Limit::perDay(3)->by('registration-email:'.hash_hmac('sha256', $canonical, config('app.key'))),
            ];
        });
        RateLimiter::for('verification-resend', fn (Request $request): array => [
            Limit::perMinute(1)->by('resend-minute:'.$request->user()->id),
            Limit::perHour(5)->by('resend-hour:'.$request->user()->id),
            Limit::perHour(20)->by('resend-ip:'.$request->ip()),
        ]);
        RateLimiter::for('verification-handler', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('verify:'.$request->user()->id.':'.$request->ip()));

        RateLimiter::for('provider-application', fn (Request $request): Limit => Limit::perDay(3)->by('provider-application:'.$request->user()->id));
        RateLimiter::for('administrator-confirmation', fn (Request $request): Limit => Limit::perMinute(5)->by('administrator-confirmation:'.$request->user()->id.':'.$request->ip()));
        RateLimiter::for('application-decision', fn (Request $request): Limit => Limit::perMinute(30)->by('application-decision:'.$request->user()->id.':'.$request->ip()));

        RateLimiter::for('inquiry-create', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('inquiry:'.$request->user()->getAuthIdentifier()));

        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('credentials:'.hash('sha256', (is_string($request->input('email')) ? strtolower(trim($request->input('email'), " \t\r\n")) : 'invalid').'|'.$request->ip())),
        ]);

        /**
         * Area eligibility only. Pair auth:sanctum with Laravel's can middleware.
         * Never use these gates alone to authorize a property or inquiry record.
         * No Gate::before administrator override: inquiry access stays explicit.
         */
        Gate::define('review-provider-applications', fn (User $user): bool => AccountEligibility::allows($user, UserRole::ADMINISTRATOR));
        Gate::define('access-administration', fn (User $user): bool => AccountEligibility::allows($user, UserRole::ADMINISTRATOR));
        Gate::define('access-provider-area', fn (User $user): bool => AccountEligibility::allows($user, UserRole::PROPERTY_PROVIDER));
        Gate::define('access-business-area', fn (User $user): bool => AccountEligibility::allows($user, UserRole::BUSINESS_USER));
    }
}
