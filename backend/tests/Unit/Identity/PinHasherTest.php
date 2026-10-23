<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Services\PinHasher;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PinHasherTest extends TestCase
{
    public function test_hmac_round_trip(): void
    {
        $hash = PinHasher::make('1234');

        $this->assertTrue(str_starts_with($hash, 'hmac:'));
        $this->assertTrue(PinHasher::check('1234', $hash));
        $this->assertFalse(PinHasher::check('0000', $hash));
        $this->assertFalse(PinHasher::needsRehash($hash));
    }

    public function test_accepts_legacy_bcrypt_and_flags_rehash(): void
    {
        $legacy = Hash::make('5678');

        $this->assertTrue(PinHasher::check('5678', $legacy));
        $this->assertFalse(PinHasher::check('1234', $legacy));
        $this->assertTrue(PinHasher::needsRehash($legacy));
    }
}
