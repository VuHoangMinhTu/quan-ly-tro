<?php

namespace Tests\Feature;

use App\Http\Middleware\SetApiLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SetApiLocale::class)->get('/api/_foundation-test/{exception}', function (string $exception) {
            match ($exception) {
                'validation' => throw new ValidationException(
                    Validator::make([], [
                        'email' => ['required', 'email'],
                    ])
                ),
                'authentication' => throw new AuthenticationException,
                'authorization' => throw new AuthorizationException,
                'not-found' => throw new ModelNotFoundException,
                'server' => throw new RuntimeException('Sensitive internal detail'),
            };
        });
    }

    public function test_api_exceptions_return_the_expected_json_statuses(): void
    {
        $this->getJson('/api/_foundation-test/validation')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Vui lòng nhập email.')
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.email.0', 'Vui lòng nhập email.')
            ->assertJsonStructure(['errors' => ['email']]);

        $this->getJson('/api/_foundation-test/authentication')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.')
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', null);

        $this->getJson('/api/_foundation-test/authorization')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Bạn không có quyền thực hiện thao tác này.')
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', null);

        $this->getJson('/api/_foundation-test/not-found')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Không tìm thấy dữ liệu.')
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors', null);
    }

    public function test_production_does_not_expose_internal_exception_details(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/_foundation-test/server')
            ->assertStatus(500)
            ->assertJsonPath('message', 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.')
            ->assertDontSee('Sensitive internal detail');
    }

    public function test_cors_allows_the_react_development_server(): void
    {
        $frontendUrl = config('app.frontend_url');

        $this->options('/api/user', [
            'Origin' => $frontendUrl,
            'Access-Control-Request-Method' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', $frontendUrl);
    }
}
