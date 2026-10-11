<?php

namespace Database\Factories;

use App\Modules\Identity\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'name' => '测试家庭'.fake()->numerify('###'),
            'timezone' => 'Asia/Shanghai',
            'locale' => 'zh-CN',
        ];
    }
}
