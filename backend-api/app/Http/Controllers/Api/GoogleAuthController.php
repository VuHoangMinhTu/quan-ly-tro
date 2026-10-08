<?php

namespace App\Http\Controllers\Api;

use App\Custom\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\{Landlord, User};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Cache, DB, Hash};
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;



class GoogleAuthController extends Controller
{
    
    public function redirect()
    {
        /** @var \Laravel\Socialite\Two\GoogleProvider $provider */
        $provider = Socialite::driver('google');

        return $provider
            ->stateless()
            ->scopes(['email', 'profile'])
            ->redirect();
    }
    public function callback()
    {
        try {
            /** @var \Laravel\Socialite\Two\GoogleProvider $provider */
            $provider = Socialite::driver('google');

            $google = $provider
                ->stateless()
                ->user();

            $user = DB::transaction(function () use ($google) {
                $user = User::where('google_id', $google->getId())->first() ?? User::where('email', $google->getEmail())->first();
                if (!$user) {
                    $user = User::create(['name' => $google->getName() ?: 'Google User', 'email' => $google->getEmail(), 'google_id' => $google->getId(), 'avatar' => $google->getAvatar(), 'password' => Hash::make(Str::random(40)), 'email_verified_at' => now()]);
                    Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '']);
                } else {
                    $user->update(['google_id' => $google->getId(), 'avatar' => $google->getAvatar(), 'email_verified_at' => $user->email_verified_at ?? now()]);
                    if (!$user->landlord) Landlord::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '']);
                }
                return $user;
            });
            $code = Str::random(64);
            Cache::put('google_login:' . $code, $user->id, now()->addMinutes(2));
            return redirect()->away(rtrim(config('app.frontend_url'), '/') . '/auth/google/callback?code=' . $code);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->away(rtrim(config('app.frontend_url'), '/') . '/login?google=error');
        }
    }
    public function exchange(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $key = 'google_login:' . $request->string('code');
        $id = Cache::pull($key);
        if (!$id) return ApiResponse::error('Invalid or expired Google login code.', null, 422);
        $user = User::with('landlord')->findOrFail($id);
        return ApiResponse::success(['token' => $user->createToken('api-token')->plainTextToken, 'user' => $user, 'landlord' => $user->landlord], 'Google login successful.');
    }
}
