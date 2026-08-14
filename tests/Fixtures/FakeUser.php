<?php

namespace Devletes\Sidekick\Tests\Fixtures;

use Illuminate\Auth\GenericUser;

class FakeUser extends GenericUser
{
    public static function make(int $id = 1): self
    {
        return new self(['id' => $id, 'name' => 'Test User']);
    }
}
