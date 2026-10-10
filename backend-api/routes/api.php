<?php

use App\Http\Controllers\Api\AmenityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardingHouseController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceItemController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayOSPaymentController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomServiceController;
use App\Http\Controllers\Api\RoomTenantController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServicePriceTierController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UtilityMeterController;
use App\Http\Controllers\Api\UtilityReadingController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/webhooks/payos', [PayOSPaymentController::class, 'webhook']);
Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');
Route::get('/email/verify/{id}/{hash}', function (int $id, string $hash) {
    $user = User::query()->findOrFail($id);
    abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
    }

    return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email-verified?status=success');
})->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    Route::get('/boarding-houses', [BoardingHouseController::class, 'index']);
    Route::post('/boarding-houses', [BoardingHouseController::class, 'store']);
    Route::get('/boarding-houses/{id}', [BoardingHouseController::class, 'show']);
    Route::put('/boarding-houses/{id}', [BoardingHouseController::class, 'update']);
    Route::delete('/boarding-houses/{id}', [BoardingHouseController::class, 'destroy']);

    Route::get('/boarding-houses/{boardingHouseId}/rooms', [RoomController::class, 'index']);
    Route::post('/boarding-houses/{boardingHouseId}/rooms', [RoomController::class, 'store']);

    Route::get('/rooms/{id}', [RoomController::class, 'show']);

    Route::put('/rooms/{id}', [RoomController::class, 'update']);
    Route::delete('/rooms/{id}', [RoomController::class, 'destroy']);
    Route::get('/rooms/{roomId}/services', [RoomServiceController::class, 'index']);
    Route::put('/rooms/{roomId}/services', [RoomServiceController::class, 'update']);
    Route::get('/rooms/{roomId}/invoices', [InvoiceController::class, 'index']);
    Route::post('/rooms/{roomId}/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
    Route::post('/invoices/{id}/generate', [InvoiceController::class, 'generate']);
    Route::post('/invoices/{invoiceId}/items', [InvoiceItemController::class, 'store']);
    Route::put('/invoice-items/{id}', [InvoiceItemController::class, 'update']);
    Route::delete('/invoice-items/{id}', [InvoiceItemController::class, 'destroy']);
    Route::get('/invoices/{invoiceId}/payments', [PaymentController::class, 'index']);
    Route::post('/invoices/{invoiceId}/payments', [PaymentController::class, 'store']);
    Route::post('/invoices/{invoiceId}/payos', [PayOSPaymentController::class, 'store']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);
    Route::put('/payments/{id}', [PaymentController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);

    Route::get('/rooms/{roomId}/utility-meters', [UtilityMeterController::class, 'index']);
    Route::post('/rooms/{roomId}/utility-meters', [UtilityMeterController::class, 'store']);
    Route::get('/utility-meters/{id}', [UtilityMeterController::class, 'show']);
    Route::put('/utility-meters/{id}', [UtilityMeterController::class, 'update']);
    Route::delete('/utility-meters/{id}', [UtilityMeterController::class, 'destroy']);
    Route::get('/utility-meters/{meterId}/readings', [UtilityReadingController::class, 'index']);
    Route::post('/utility-meters/{meterId}/readings', [UtilityReadingController::class, 'store']);
    Route::get('/utility-readings/{id}', [UtilityReadingController::class, 'show']);
    Route::put('/utility-readings/{id}', [UtilityReadingController::class, 'update']);
    Route::delete('/utility-readings/{id}', [UtilityReadingController::class, 'destroy']);

    Route::get('/rooms/{roomId}/tenants', [RoomTenantController::class, 'index']);
    Route::post('/rooms/{roomId}/tenants', [RoomTenantController::class, 'store']);
    Route::put('/room-tenants/{id}', [RoomTenantController::class, 'update']);
    Route::delete('/room-tenants/{id}', [RoomTenantController::class, 'destroy']);

    Route::get('/rooms/{roomId}/contracts', [ContractController::class, 'index']);
    Route::post('/rooms/{roomId}/contracts', [ContractController::class, 'store']);
    Route::get('/contracts/{id}', [ContractController::class, 'show']);
    Route::put('/contracts/{id}', [ContractController::class, 'update']);
    Route::delete('/contracts/{id}', [ContractController::class, 'destroy']);

    Route::get('/boarding-houses/{boardingHouseId}/services', [ServiceController::class, 'index']);
    Route::post('/boarding-houses/{boardingHouseId}/services', [ServiceController::class, 'store']);
    Route::get('/services/{id}', [ServiceController::class, 'show']);
    Route::put('/services/{id}', [ServiceController::class, 'update']);
    Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

    Route::get('/services/{serviceId}/price-tiers', [ServicePriceTierController::class, 'index']);
    Route::post('/services/{serviceId}/price-tiers', [ServicePriceTierController::class, 'store']);
    Route::put('/service-price-tiers/{id}', [ServicePriceTierController::class, 'update']);
    Route::delete('/service-price-tiers/{id}', [ServicePriceTierController::class, 'destroy']);

    Route::get('/amenities', [AmenityController::class, 'index']);
    Route::post('/amenities', [AmenityController::class, 'store']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'show']);
    Route::put('/tenants/{id}', [TenantController::class, 'update']);
    Route::delete('/tenants/{id}', [TenantController::class, 'destroy']);
});
