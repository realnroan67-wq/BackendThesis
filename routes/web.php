<?php

use App\Http\Controllers\Api\V1\ProviderApplicationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

/** Web middleware supplies encrypted cookies, sessions and CSRF verification. */
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware(['guest:web', 'throttle:login'])->name('login');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:web')->name('logout');

/** Default-off registration; verification remains usable for existing restricted accounts. */
Route::post('/register', RegistrationController::class)
    ->middleware('throttle:registration')->name('register');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth:web', 'throttle:verification-resend'])->name('verification.send');
Route::get('/api/v1/auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['auth:web', 'throttle:verification-handler'])->name('verification.verify');

/** Explicit web sessions bind fresh Administrator confirmation to decisions. */
Route::post('/api/v1/admin/password-confirmation', [ProviderApplicationController::class, 'confirm'])
    ->middleware(['auth:web', 'throttle:administrator-confirmation'])->name('api.v1.admin.password-confirmation');
Route::patch('/api/v1/admin/provider-applications/{id}/decision', [ProviderApplicationController::class, 'decision'])
    ->middleware(['auth:web', 'throttle:application-decision'])->name('api.v1.admin.provider-applications.decision');
