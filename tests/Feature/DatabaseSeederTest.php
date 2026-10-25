<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_seeding_is_repeatable_and_does_not_create_login_accounts(): void
    {
        $existing = User::factory()->create();
        $count = User::count();
        $this->seed();
        $this->seed();
        $this->assertSame($count, User::count());
        $this->assertSame($existing->email, $existing->fresh()->email);
    }
}
