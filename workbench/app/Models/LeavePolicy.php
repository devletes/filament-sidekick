<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeavePolicy extends Model
{
    protected $guarded = [];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
