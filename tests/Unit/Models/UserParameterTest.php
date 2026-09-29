<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserParameterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_user_parameter(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'seat',
            'value' => 'A42',
        ]);

        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'key' => 'seat',
        ]);
        $this->assertEquals('A42', $param->value);
    }

    public function test_unique_constraint_on_user_and_key(): void
    {
        $user = User::factory()->create();
        UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'seat',
            'value' => 'A42',
        ]);

        $this->expectException(QueryException::class);
        UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'seat',
            'value' => 'B17',
        ]);
    }

    public function test_value_casts_to_json(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'preferences',
            'value' => ['theme' => 'dark', 'lang' => 'en'],
        ]);

        $param->refresh();
        $this->assertEquals('dark', $param->value['theme']);
        $this->assertEquals('en', $param->value['lang']);
    }

    public function test_user_has_parameters_relationship(): void
    {
        $user = User::factory()->create();
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat', 'value' => 'A42']);
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'team', 'value' => 'Red']);

        $this->assertCount(2, $user->parameters);
        $this->assertInstanceOf(HasMany::class, $user->parameters());
        $this->assertEquals(
            ['seat' => 'A42', 'team' => 'Red'],
            $user->parameters->pluck('value', 'key')->toArray()
        );
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat']);

        $this->assertTrue($param->user->is($user));
    }

    public function test_cascade_delete_removes_parameters(): void
    {
        $user = User::factory()->create();
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat', 'value' => 'A42']);
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'team', 'value' => 'Red']);

        $this->assertDatabaseCount('user_parameters', 2);
        $user->delete();
        $this->assertDatabaseCount('user_parameters', 0);
    }

    public function test_fillable_attributes(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::create([
            'user_id' => $user->id,
            'key' => 'department',
            'value' => 'Engineering',
        ]);

        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'key' => 'department',
        ]);
        $this->assertEquals('Engineering', $param->value);
    }

    public function test_value_can_be_null(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'optional',
            'value' => null,
        ]);

        $param->refresh();
        $this->assertNull($param->value);
    }

    public function test_different_users_can_have_same_key(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserParameter::factory()->create(['user_id' => $user1->id, 'key' => 'seat', 'value' => 'A42']);
        UserParameter::factory()->create(['user_id' => $user2->id, 'key' => 'seat', 'value' => 'B17']);

        $this->assertDatabaseCount('user_parameters', 2);
    }
}
