<?php

namespace Tests\Feature;

use App\Models\User;
use App\Policies\UserPolicy;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    public function test_unsaved_users_cannot_view_each_other_as_the_same_profile(): void
    {
        $actor = User::factory()->make();
        $subject = User::factory()->make();
        $this->assertFalse((new UserPolicy)->view($actor, $subject));
    }

    public function test_an_unsaved_user_cannot_authorize_its_own_profile(): void
    {
        $actor = User::factory()->make();
        $this->assertFalse((new UserPolicy)->view($actor, $actor));
    }
}
