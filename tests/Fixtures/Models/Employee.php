<?php

namespace Devletes\Sidekick\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as AuthUser;

class Employee extends AuthUser
{
    protected $guarded = [];

    protected $table = 'employees';
}
