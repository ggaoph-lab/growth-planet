<?php

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Identity\Actions\RegisterFamilyWithInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class RegisterController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 15 * 60;

    public function __construct(private readonly RegisterFamilyWithInvite $register) {}

    public function store(Request $request): JsonResponse
    {
        $throttleKey = 'family-register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }

        $data = $request->validate([
            'invite_code' => ['required', 'string', 'max:64'],
            'family_name' => ['required', 'string', 'max:64'],
            'timezone' => ['required', 'timezone:all'],
            'display_name' => ['required', 'string', 'max:32'],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{3,32}$/'],
            'password' => ['required', 'string', Password::min(10)],
        ]);

        try {
            $result = $this->register->handle(
                $data['invite_code'],
                $data['family_name'],
                $data['timezone'],
                $data['display_name'],
                $data['username'],
                $data['password'],
            );
        } catch (ApiException $e) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            throw $e;
        }

        return response()->json(['data' => $result], 201);
    }
}
