<?php

use App\Http\Controllers\Api\AmenityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BedController;
use App\Http\Controllers\Api\BoardingHouseController;
use App\Http\Controllers\Api\BoardingHouseManagerController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\ContractDocumentController;
use App\Http\Controllers\Api\ContractHandoverController;
use App\Http\Controllers\Api\ContractServiceController;
use App\Http\Controllers\Api\ContractSigningController;
use App\Http\Controllers\Api\ContractTemplateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FloorController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceItemController;
use App\Http\Controllers\Api\LandlordPaymentSettingsController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayOSPaymentController;
use App\Http\Controllers\Api\PublicContractSigningController;
use App\Http\Controllers\Api\ResidenceDeclarationController;
use App\Http\Controllers\Api\ResidenceProfileController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomServiceController;
use App\Http\Controllers\Api\RoomTenantController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServicePriceTierController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UtilityMeterController;
use App\Http\Controllers\Api\UtilityReadingController;
use App\Http\Middleware\SigningPrivacy;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::middleware([SigningPrivacy::class, 'throttle:signing-public'])->prefix('sign/{token}')->group(function (): void {
    Route::get('/', [PublicContractSigningController::class, 'show']);
    Route::post('/request-otp', [PublicContractSigningController::class, 'requestOtp'])->middleware('throttle:signing-otp');
    Route::post('/verify-otp', [PublicContractSigningController::class, 'verifyOtp'])->middleware('throttle:signing-verify');
    Route::post('/', [PublicContractSigningController::class, 'sign']);
    Route::post('/decline', [PublicContractSigningController::class, 'decline']);
    Route::get('/signatures/{signatureId}', [PublicContractSigningController::class, 'image']);
});
Route::post('/login', [AuthController::class, 'login']);
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/webhooks/payos', [PayOSPaymentController::class, 'webhook']);
Route::post('/webhooks/payos/{webhookUuid}', [PayOSPaymentController::class, 'landlordWebhook']);
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
    Route::middleware([SigningPrivacy::class, 'throttle:signing-public'])->group(function (): void {
        Route::get('/contracts/{id}/signing', [ContractSigningController::class, 'status']);
        Route::get('/contracts/{id}/signature-events', [ContractSigningController::class, 'status']);
        Route::post('/contracts/{id}/signing-requests', [ContractSigningController::class, 'store'])->middleware('throttle:signing-otp');
        Route::post('/contracts/{id}/signing-requests/resend', [ContractSigningController::class, 'resend'])->middleware('throttle:signing-otp');
        Route::get('/contracts/{id}/sign/lessor', [ContractSigningController::class, 'context']);
        Route::post('/contracts/{id}/sign/lessor/request-otp', [ContractSigningController::class, 'requestOtp'])->middleware('throttle:signing-otp');
        Route::post('/contracts/{id}/sign/lessor/verify-otp', [ContractSigningController::class, 'verifyOtp'])->middleware('throttle:signing-verify');
        Route::post('/contracts/{id}/sign/lessor', [ContractSigningController::class, 'sign']);
        Route::post('/contracts/{id}/signing/void', [ContractSigningController::class, 'void']);
        Route::get('/contracts/{id}/document/signed', [ContractSigningController::class, 'signed']);
        Route::get('/contracts/{id}/signatures/{signatureId}/image', [ContractSigningController::class, 'image']);
    });
    Route::get('/contract-templates', [ContractTemplateController::class, 'index']);
    Route::post('/contract-templates', [ContractTemplateController::class, 'store']);
    Route::post('/contract-templates/preview', [ContractTemplateController::class, 'preview']);
    Route::get('/contract-templates/{id}', [ContractTemplateController::class, 'show']);
    Route::put('/contract-templates/{id}', [ContractTemplateController::class, 'update']);
    Route::post('/contract-templates/{id}/versions', [ContractTemplateController::class, 'version']);
    Route::put('/contract-templates/{id}/status', [ContractTemplateController::class, 'status']);
    Route::delete('/contract-templates/{id}', [ContractTemplateController::class, 'destroy']);
    Route::get('/contracts/{id}/document/preview', [ContractDocumentController::class, 'preview']);
    Route::get('/contracts/{id}/document', [ContractDocumentController::class, 'show']);
    Route::post('/contracts/{id}/finalize', [ContractDocumentController::class, 'finalize']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/residence/boarding-houses', [ResidenceDeclarationController::class, 'houses']);
    Route::get('/residence/boarding-houses/{id}/rooms', [ResidenceDeclarationController::class, 'rooms']);
    Route::get('/residence/rooms/{id}/context', [ResidenceDeclarationController::class, 'context']);
    Route::get('/boarding-houses/{id}/residence-profile', [ResidenceProfileController::class, 'show']);
    Route::put('/boarding-houses/{id}/residence-profile', [ResidenceProfileController::class, 'update']);
    Route::get('/boarding-houses/{id}/managers', [BoardingHouseManagerController::class, 'index']);
    Route::put('/boarding-houses/{id}/managers', [BoardingHouseManagerController::class, 'update']);
    Route::get('/residence-declarations', [ResidenceDeclarationController::class, 'index']);
    Route::post('/residence-declarations', [ResidenceDeclarationController::class, 'store']);
    Route::get('/residence-declarations/{id}', [ResidenceDeclarationController::class, 'show']);
    Route::put('/residence-declarations/{id}', [ResidenceDeclarationController::class, 'update']);
    Route::put('/residence-declarations/{id}/status', [ResidenceDeclarationController::class, 'status']);
    Route::get('/residence-declarations/{id}/export', [ResidenceDeclarationController::class, 'export']);
    Route::get('/residence-declarations/{id}/documents/residence-stay-consent', [ResidenceDeclarationController::class, 'stayConsent']);
    Route::delete('/residence-declarations/{id}', [ResidenceDeclarationController::class, 'destroy']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/landlord/payment-profile', [LandlordPaymentSettingsController::class, 'profile']);
    Route::put('/landlord/payment-profile', [LandlordPaymentSettingsController::class, 'saveProfile']);
    Route::get('/landlord/payos-config', [LandlordPaymentSettingsController::class, 'payos']);
    Route::put('/landlord/payos-config', [LandlordPaymentSettingsController::class, 'savePayos']);
    Route::delete('/landlord/payos-config', [LandlordPaymentSettingsController::class, 'deletePayos']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    Route::get('/boarding-houses', [BoardingHouseController::class, 'index']);
    Route::post('/boarding-houses', [BoardingHouseController::class, 'store']);
    Route::post('/boarding-houses/with-rooms', [BoardingHouseController::class, 'storeWithRooms']);
    Route::get('/boarding-houses/{id}', [BoardingHouseController::class, 'show']);
    Route::put('/boarding-houses/{id}', [BoardingHouseController::class, 'update']);
    Route::delete('/boarding-houses/{id}', [BoardingHouseController::class, 'destroy']);

    Route::get('/boarding-houses/{boardingHouseId}/rooms', [RoomController::class, 'index']);
    Route::get('/boarding-houses/{boardingHouseId}/floors', [FloorController::class, 'index']);
    Route::post('/boarding-houses/{boardingHouseId}/floors', [FloorController::class, 'store']);
    Route::post('/boarding-houses/{boardingHouseId}/rooms', [RoomController::class, 'store']);
    Route::post('/boarding-houses/{boardingHouseId}/rooms/bulk', [RoomController::class, 'storeBulk']);

    Route::get('/rooms/{id}', [RoomController::class, 'show']);
    Route::get('/rooms/{roomId}/beds', [BedController::class, 'index']);
    Route::post('/rooms/{roomId}/beds', [BedController::class, 'store']);
    Route::put('/beds/{id}', [BedController::class, 'update']);

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
    Route::get('/rooms/{roomId}/contract-context', [ContractController::class, 'context']);
    Route::put('/boarding-houses/{id}/contract-schedule', [ContractController::class, 'schedule']);
    Route::put('/contracts/{id}/status', [ContractController::class, 'status']);
    Route::put('/contracts/{id}/room', [ContractController::class, 'changeRoom']);
    Route::put('/contracts/{id}/occupants/{occupantId}/move-out', [ContractController::class, 'moveOut']);
    Route::post('/rooms/{roomId}/contracts', [ContractController::class, 'store']);
    Route::get('/contracts/{id}', [ContractController::class, 'show']);
    Route::get('/contracts/{id}/handover-check-in', [ContractHandoverController::class, 'show']);
    Route::post('/contracts/{id}/handover-check-in', [ContractHandoverController::class, 'store']);
    Route::put('/contracts/{id}/handover-check-in', [ContractHandoverController::class, 'update']);
    Route::post('/contracts/{id}/handover-check-in/confirm', [ContractHandoverController::class, 'confirm']);
    Route::get('/contracts/{id}/services', [ContractServiceController::class, 'index']);
    Route::put('/contracts/{id}/services', [ContractServiceController::class, 'update']);
    Route::post('/contracts/{id}/service-adjustments', [ContractServiceController::class, 'adjust']);
    Route::post('/contracts/{id}/services/{termId}/stop', [ContractServiceController::class, 'stop']);
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
