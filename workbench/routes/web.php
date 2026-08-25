<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

// Screenshot helper: the capture script needs several users in one run, which
// trips Filament's login throttle. Workbench-only, never shipped.
Route::get('/dev-login/{email}', function (string $email) {
    abort_unless(app()->environment(['local', 'testing']), 404);

    auth()->login(User::query()->where('email', $email)->firstOrFail());

    return redirect('/admin');
});
