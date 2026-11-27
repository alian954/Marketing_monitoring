<?php

namespace App\Http\Controllers;

use App\Services\SystemRuntimeHealth;
use Illuminate\View\View;

class PlatformSystemStatusController extends Controller
{
    public function index(SystemRuntimeHealth $systemRuntimeHealth): View
    {
        return view('platform.system.index', $systemRuntimeHealth->snapshot(now()->toImmutable()));
    }
}
