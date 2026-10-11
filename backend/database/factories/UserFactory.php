<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => 'user_'.fake()->unique()->numerify('#######'),
            'email' => null,
            'password' => 'test-password-'.fake()->numerify('####'),
        ];
    }
}
