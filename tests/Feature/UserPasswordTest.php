<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_user_can_have_password_set(): void
    {
        $user = User::factory()->create();
        $user->password = Hash::make('secret123');
        $user->save();

        $user->refresh();
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_password_is_nullable_by_default(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->password);
    }

    public function test_password_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $user->password = Hash::make('secret');
        $user->save();

        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
    }

    public function test_factory_with_password_state(): void
    {
        $user = User::factory()->withPassword('testpass123')->create();

        $this->assertTrue(Hash::check('testpass123', $user->password));
    }

    public function test_password_is_hashed_via_cast(): void
    {
        $user = User::factory()->create();
        $user->password = 'plaintext_password';
        $user->save();
        $user->refresh();

        // The 'hashed' cast should auto-hash the password
        $this->assertTrue(Hash::check('plaintext_password', $user->password));
        $this->assertNotEquals('plaintext_password', $user->password);
    }
}
