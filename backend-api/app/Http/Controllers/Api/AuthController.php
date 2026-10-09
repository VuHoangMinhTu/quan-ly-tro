<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Landlord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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
}
