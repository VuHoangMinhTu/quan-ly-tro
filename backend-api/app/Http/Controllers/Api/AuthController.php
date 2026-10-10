<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const RESET_LINK_MESSAGE = 'Nếu địa chỉ email này tồn tại trong hệ thống, BeeHouse đã gửi liên kết đặt lại mật khẩu. Vui lòng kiểm tra hộp thư.';

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $landlord] = DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $landlord = Landlord::create([
                'user_id' => $user->id,
                'full_name' => $data['name'],
                'phone' => $data['phone'],
            ]);

            return [$user, $landlord];
        });

        $user->sendEmailVerificationNotification();

        return ApiResponse::success(null, 'Đăng ký thành công. Vui lòng xác minh email.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->with('landlord')->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('Email hoặc mật khẩu không chính xác.', null, 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return ApiResponse::error('Vui lòng xác minh email trước khi đăng nhập.', null, 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return ApiResponse::success([
            'user' => $user,
            'landlord' => $user->landlord,
            'token' => $token,
        ], 'Đăng nhập thành công.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Đăng xuất thành công.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('landlord');

        return ApiResponse::success([
            'user' => $user,
            'landlord' => $user->landlord,
        ], 'Lấy thông tin tài khoản thành công.');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user) {
            return ApiResponse::success(null, 'Nếu tài khoản tồn tại, email xác minh đã được gửi.');
        }

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(null, 'Email đã được xác minh.');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::success(null, 'Gửi email xác minh thành công.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limiterKey = 'password-reset:'.sha1(mb_strtolower($data['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            return ApiResponse::error('Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau.', null, 429);
        }

        RateLimiter::hit($limiterKey, 60);

        // The same response for a missing user, a sent link, and broker throttling prevents email enumeration.
        Password::sendResetLink(['email' => $data['email']]);

        return ApiResponse::success(null, self::RESET_LINK_MESSAGE);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            return ApiResponse::error(
                'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.',
                null,
                422,
            );
        }

        return ApiResponse::success(null, 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error('Mật khẩu hiện tại không chính xác.', [
                'current_password' => ['Mật khẩu hiện tại không chính xác.'],
            ], 422);
        }

        if (Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('Mật khẩu mới phải khác mật khẩu hiện tại.', [
                'password' => ['Mật khẩu mới phải khác mật khẩu hiện tại.'],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->setRememberToken(Str::random(60));

        $user->save();

        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->getKey())->delete();
        } else {
            $user->tokens()->delete();
        }

        return ApiResponse::success(null, 'Đổi mật khẩu thành công.');
    }
}
