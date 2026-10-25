<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InquiryController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\ProviderApplicationController;
use Illuminate\Support\Facades\Route;

/** Public development readiness. Restrict/separate readiness before production. */
Route::get('/v1/health', HealthController::class)->name('api.v1.health');

Route::get('/v1/me', CurrentUserController::class)->middleware('auth:sanctum')->name('api.v1.me');

Route::post('/v1/inquiries', [InquiryController::class, 'store'])->middleware(['auth:sanctum', 'throttle:inquiry-create'])->name('api.v1.inquiries.store');

/** Public reads only; no publishing, ownership changes or management permissions. */
Route::get('/v1/properties', [PropertyController::class, 'index'])->name('api.v1.properties.index');
Route::get('/v1/properties/{id}', [PropertyController::class, 'show'])->name('api.v1.properties.show');

/** Participant management; all status writes use the transactionally rechecked close service. */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/v1/inquiries', [InquiryController::class, 'index'])->name('api.v1.inquiries.index');
    Route::get('/v1/inquiries/{id}', [InquiryController::class, 'show'])->name('api.v1.inquiries.show');
    Route::patch('/v1/provider/inquiries/{id}/status', [InquiryController::class, 'updateStatus'])->name('api.v1.provider.inquiries.status');
    Route::get('/v1/provider/inquiries', [InquiryController::class, 'providerIndex'])->name('api.v1.provider.inquiries.index');
});

Route::get('/v1/onboarding', OnboardingController::class)
    ->middleware('auth:sanctum')->name('api.v1.onboarding');

/** Public application creation is gated; existing review remains available without activation. */
Route::post('/v1/provider-applications', [ProviderApplicationController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:provider-application'])->name('api.v1.provider-applications.store');
Route::get('/v1/admin/provider-applications', [ProviderApplicationController::class, 'index'])
    ->middleware('auth:sanctum')->name('api.v1.admin.provider-applications.index');
Route::get('/v1/admin/provider-applications/{id}', [ProviderApplicationController::class, 'show'])
    ->middleware('auth:sanctum')->name('api.v1.admin.provider-applications.show');
