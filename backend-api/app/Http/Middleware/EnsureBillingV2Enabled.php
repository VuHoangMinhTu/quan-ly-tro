<?php

namespace App\Http\Middleware;

use App\Custom\ApiResponse;
use App\Services\BillingV2Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingV2Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(BillingV2Access::class)->enabledFor($request->user())) {
            return ApiResponse::error('Billing V2 chưa được bật cho tài khoản này. Dữ liệu và hóa đơn hiện có vẫn được giữ nguyên.', null, 503);
        }

        return $next($request);
    }
}
