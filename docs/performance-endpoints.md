# Endpoint inventory — 2026-10-25

Generated from local `php artisan route:list --path=api --except-vendor --json --no-interaction`. This is the registered route table, not evidence that production has the same revision. All paths below already include `/api`. No endpoint was called by this inventory command.

| Method | Path | Action | Middleware |
| --- | --- | --- | --- |
| GET, HEAD | `/api/amenities` | `AmenityController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/amenities` | `AmenityController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/auth/forgot-password` | `AuthController@forgotPassword` | `api` |
| GET, HEAD | `/api/auth/google/callback` | `GoogleAuthController@callback` | `api` |
| POST | `/api/auth/google/exchange` | `GoogleAuthController@exchange` | `api` |
| GET, HEAD | `/api/auth/google/redirect` | `GoogleAuthController@redirect` | `api` |
| PUT | `/api/auth/password` | `AuthController@changePassword` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/auth/reset-password` | `AuthController@resetPassword` | `api` |
| PUT | `/api/beds/{id}` | `BedController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses` | `BoardingHouseController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/boarding-houses` | `BoardingHouseController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/boarding-houses/with-rooms` | `BoardingHouseController@storeWithRooms` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses/{boardingHouseId}/floors` | `FloorController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/boarding-houses/{boardingHouseId}/floors` | `FloorController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses/{boardingHouseId}/rooms` | `RoomController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/boarding-houses/{boardingHouseId}/rooms` | `RoomController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/boarding-houses/{boardingHouseId}/rooms/bulk` | `RoomController@storeBulk` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/boarding-houses/{boardingHouseId}/rooms/order` | `RoomController@reorder` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses/{boardingHouseId}/services` | `ServiceController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/boarding-houses/{boardingHouseId}/services` | `ServiceController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses/{id}` | `BoardingHouseController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/boarding-houses/{id}` | `BoardingHouseController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/boarding-houses/{id}` | `BoardingHouseController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/boarding-houses/{id}/contract-schedule` | `ContractController@schedule` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses/{id}/managers` | `BoardingHouseManagerController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/boarding-houses/{id}/managers` | `BoardingHouseManagerController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/boarding-houses/{id}/residence-profile` | `ResidenceProfileController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/boarding-houses/{id}/residence-profile` | `ResidenceProfileController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contract-templates` | `ContractTemplateController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contract-templates` | `ContractTemplateController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contract-templates/preview` | `ContractTemplateController@preview` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contract-templates/{id}` | `ContractTemplateController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contract-templates/{id}` | `ContractTemplateController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/contract-templates/{id}` | `ContractTemplateController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contract-templates/{id}/status` | `ContractTemplateController@status` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contract-templates/{id}/versions` | `ContractTemplateController@version` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}` | `ContractController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contracts/{id}` | `ContractController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/contracts/{id}` | `ContractController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}/document` | `ContractDocumentController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}/document/preview` | `ContractDocumentController@preview` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}/document/signed` | `ContractSigningController@signed` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/contracts/{id}/finalize` | `ContractDocumentController@finalize` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}/handover-check-in` | `ContractHandoverController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contracts/{id}/handover-check-in` | `ContractHandoverController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contracts/{id}/handover-check-in` | `ContractHandoverController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contracts/{id}/handover-check-in/confirm` | `ContractHandoverController@confirm` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contracts/{id}/occupants/{occupantId}/move-out` | `ContractController@moveOut` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contracts/{id}/room` | `ContractController@changeRoom` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contracts/{id}/service-adjustments` | `ContractServiceController@adjust` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}/services` | `ContractServiceController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/contracts/{id}/services` | `ContractServiceController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/contracts/{id}/services/{termId}/stop` | `ContractServiceController@stop` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/contracts/{id}/sign/lessor` | `ContractSigningController@context` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/contracts/{id}/sign/lessor` | `ContractSigningController@sign` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/contracts/{id}/sign/lessor/request-otp` | `ContractSigningController@requestOtp` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-otp` |
| POST | `/api/contracts/{id}/sign/lessor/verify-otp` | `ContractSigningController@verifyOtp` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-verify` |
| GET, HEAD | `/api/contracts/{id}/signature-events` | `ContractSigningController@status` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| GET, HEAD | `/api/contracts/{id}/signatures/{signatureId}/image` | `ContractSigningController@image` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| GET, HEAD | `/api/contracts/{id}/signing` | `ContractSigningController@status` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/contracts/{id}/signing-requests` | `ContractSigningController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-otp` |
| POST | `/api/contracts/{id}/signing-requests/resend` | `ContractSigningController@resend` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-otp` |
| POST | `/api/contracts/{id}/signing/void` | `ContractSigningController@void` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| PUT | `/api/contracts/{id}/status` | `ContractController@status` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/dashboard` | `DashboardController` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/email/verification-notification` | `AuthController@resendVerification` | `api`, `Illuminate\Routing\Middleware\ThrottleRequests:6,1` |
| GET, HEAD | `/api/email/verify/{id}/{hash}` | `Closure` | `api`, `Illuminate\Routing\Middleware\ValidateSignature`, `Illuminate\Routing\Middleware\ThrottleRequests:6,1` |
| PUT | `/api/invoice-items/{id}` | `InvoiceItemController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/invoice-items/{id}` | `InvoiceItemController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/invoices/{id}` | `InvoiceController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/invoices/{id}` | `InvoiceController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/invoices/{id}` | `InvoiceController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/invoices/{id}/generate` | `InvoiceController@generate` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/invoices/{id}/payment-qr` | `InvoiceController@paymentQr` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/invoices/{invoiceId}/items` | `InvoiceItemController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/invoices/{invoiceId}/payments` | `PaymentController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/invoices/{invoiceId}/payments` | `PaymentController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/invoices/{invoiceId}/payos` | `PayOSPaymentController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/landlord/payment-profile` | `LandlordPaymentSettingsController@profile` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/landlord/payment-profile` | `LandlordPaymentSettingsController@saveProfile` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/landlord/payment-profile/qr` | `LandlordPaymentSettingsController@qr` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/landlord/payos-config` | `LandlordPaymentSettingsController@payos` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/landlord/payos-config` | `LandlordPaymentSettingsController@savePayos` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/landlord/payos-config` | `LandlordPaymentSettingsController@deletePayos` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/login` | `AuthController@login` | `api` |
| POST | `/api/logout` | `AuthController@logout` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/me` | `AuthController@me` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/payments/{id}` | `PaymentController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/payments/{id}` | `PaymentController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/payments/{id}` | `PaymentController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/register` | `AuthController@register` | `api` |
| GET, HEAD | `/api/residence-declarations` | `ResidenceDeclarationController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/residence-declarations` | `ResidenceDeclarationController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/residence-declarations/{id}` | `ResidenceDeclarationController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/residence-declarations/{id}` | `ResidenceDeclarationController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/residence-declarations/{id}` | `ResidenceDeclarationController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/residence-declarations/{id}/documents/residence-stay-consent` | `ResidenceDeclarationController@stayConsent` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/residence-declarations/{id}/export` | `ResidenceDeclarationController@export` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/residence-declarations/{id}/status` | `ResidenceDeclarationController@status` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/residence/boarding-houses` | `ResidenceDeclarationController@houses` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/residence/boarding-houses/{id}/rooms` | `ResidenceDeclarationController@rooms` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/residence/rooms/{id}/context` | `ResidenceDeclarationController@context` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/room-tenants/{id}` | `RoomTenantController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/room-tenants/{id}` | `RoomTenantController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{id}` | `RoomController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/rooms/{id}` | `RoomController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/rooms/{id}` | `RoomController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/beds` | `BedController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/rooms/{roomId}/beds` | `BedController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/contract-context` | `ContractController@context` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/contracts` | `ContractController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/rooms/{roomId}/contracts` | `ContractController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/invoices` | `InvoiceController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/rooms/{roomId}/invoices` | `InvoiceController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/services` | `RoomServiceController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/rooms/{roomId}/services` | `RoomServiceController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/tenants` | `RoomTenantController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/rooms/{roomId}/tenants` | `RoomTenantController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/rooms/{roomId}/utility-meters` | `UtilityMeterController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/rooms/{roomId}/utility-meters` | `UtilityMeterController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/service-price-tiers/{id}` | `ServicePriceTierController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/service-price-tiers/{id}` | `ServicePriceTierController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/services/{id}` | `ServiceController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/services/{id}` | `ServiceController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/services/{id}` | `ServiceController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/services/{serviceId}/price-tiers` | `ServicePriceTierController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/services/{serviceId}/price-tiers` | `ServicePriceTierController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/sign/{token}` | `PublicContractSigningController@show` | `api`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/sign/{token}` | `PublicContractSigningController@sign` | `api`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/sign/{token}/decline` | `PublicContractSigningController@decline` | `api`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/sign/{token}/request-otp` | `PublicContractSigningController@requestOtp` | `api`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-otp` |
| GET, HEAD | `/api/sign/{token}/signatures/{signatureId}` | `PublicContractSigningController@image` | `api`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public` |
| POST | `/api/sign/{token}/verify-otp` | `PublicContractSigningController@verifyOtp` | `api`, `App\Http\Middleware\SigningPrivacy`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-public`, `Illuminate\Routing\Middleware\ThrottleRequests:signing-verify` |
| GET, HEAD | `/api/tenants` | `TenantController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/tenants` | `TenantController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/tenants/{id}` | `TenantController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/tenants/{id}` | `TenantController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/tenants/{id}` | `TenantController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/utility-meters/{id}` | `UtilityMeterController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/utility-meters/{id}` | `UtilityMeterController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/utility-meters/{id}` | `UtilityMeterController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/utility-meters/{meterId}/readings` | `UtilityReadingController@index` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/utility-meters/{meterId}/readings` | `UtilityReadingController@store` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| GET, HEAD | `/api/utility-readings/{id}` | `UtilityReadingController@show` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| PUT | `/api/utility-readings/{id}` | `UtilityReadingController@update` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| DELETE | `/api/utility-readings/{id}` | `UtilityReadingController@destroy` | `api`, `Illuminate\Auth\Middleware\Authenticate:sanctum` |
| POST | `/api/webhooks/payos` | `PayOSPaymentController@webhook` | `api` |
| POST | `/api/webhooks/payos/{webhookUuid}` | `PayOSPaymentController@landlordWebhook` | `api` |
