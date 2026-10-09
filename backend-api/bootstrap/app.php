<?php

use App\Custom\ApiResponse;
use App\Exceptions\InvoiceFinancialEditException;
use App\Exceptions\PayOSReconciliationException;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [SetApiLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel normally ignores every HTTP exception, including abort(500).
        // Keep expected 4xx quiet, but log full diagnostics for unexpected 5xx.
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->dontReportWhen(fn (Throwable $exception): bool => $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);

        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if ($request->is('api/email/verify/*')) {
                return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email-verified?status=invalid');
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $errors = $exception->errors();

            return ApiResponse::error(collect($errors)->flatten()->first() ?? 'Dữ liệu không hợp lệ.', $errors, 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', null, 401);
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Bạn không có quyền thực hiện thao tác này.', null, 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Laravel wraps model-not-found exceptions in HTTP exceptions before rendering.
            $modelException = $exception instanceof ModelNotFoundException ? $exception : $exception->getPrevious();
            $resource = $modelException instanceof ModelNotFoundException
                ? class_basename($modelException->getModel() ?? '')
                : '';
            $messages = [
                'BoardingHouse' => 'Không tìm thấy nhà trọ.',
                'Room' => 'Không tìm thấy phòng.',
                'Tenant' => 'Không tìm thấy người thuê.',
                'Contract' => 'Không tìm thấy hợp đồng.',
                'RoomTenant' => 'Không tìm thấy người ở trong phòng.',
                'Amenity' => 'Không tìm thấy tiện nghi.',
                'Service' => 'Không tìm thấy dịch vụ.',
                'ServicePriceTier' => 'Không tìm thấy bậc giá.',
                'UtilityMeter' => 'Không tìm thấy đồng hồ điện/nước.',
                'UtilityReading' => 'Không tìm thấy chỉ số điện/nước.',
                'Invoice' => 'Không tìm thấy hóa đơn.',
                'InvoiceItem' => 'Không tìm thấy khoản thu.',
                'Payment' => 'Không tìm thấy thanh toán.',
            ];

            return ApiResponse::error($messages[$resource] ?? 'Không tìm thấy dữ liệu.', null, 404);
        });

        $exceptions->render(function (PayOSReconciliationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                $exception->status() >= 500 ? 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.' : $exception->getMessage(),
                null,
                $exception->status(),
            );
        });

        $exceptions->render(function (InvoiceFinancialEditException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), null, 422);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Preserve HTTP statuses without exposing framework/SQL/SDK exception text.
            // Laravel's default reporter logs unexpected exceptions with their full context.
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = match ($status) {
                400 => 'Yêu cầu không hợp lệ.',
                401 => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.',
                403 => 'Bạn không có quyền thực hiện thao tác này.',
                404 => 'Không tìm thấy dữ liệu.',
                405 => 'Phương thức yêu cầu không được hỗ trợ.',
                409 => 'Yêu cầu bị xung đột với dữ liệu hiện tại. Vui lòng tải lại và thử lại.',
                419 => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.',
                422 => 'Dữ liệu không hợp lệ.',
                429 => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.',
                default => $status >= 500 ? 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.' : 'Yêu cầu không hợp lệ.',
            };
            $response = ApiResponse::error($message, null, $status);

            if ($exception instanceof HttpExceptionInterface) {
                $response->headers->add($exception->getHeaders());
            }

            return $response;
        });
    })->create();
