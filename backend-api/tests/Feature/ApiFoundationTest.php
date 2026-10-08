<?php

namespace Tests\Feature;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
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

        Route::get('/api/_foundation-test/{exception}', function (string $exception) {
            match ($exception) {
                'validation' => throw new ValidationException(
                    Validator::make([], [
                    'email' => ['required', 'email']
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
            ->assertJsonPath('message', 'The given data was invalid.')
            // Chỉ kiểm tra sự tồn tại của Key/Schema, không quan tâm giá trị Value là gì.
            ->assertJsonStructure(['errors' => ['email']]);

        $this->getJson('/api/_foundation-test/authentication')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->getJson('/api/_foundation-test/authorization')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden.');

        $this->getJson('/api/_foundation-test/not-found')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Not Found.');
    }

    public function test_production_does_not_expose_internal_exception_details(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/_foundation-test/server')
            ->assertStatus(500)
            ->assertJsonPath('message', 'Server Error.')
            ->assertDontSee('Sensitive internal detail');
    }

    public function test_cors_allows_the_react_development_server(): void
    {
        $this->options('/api/user', [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
