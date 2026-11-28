<?php

namespace App\Http\Controllers;

use App\Services\OAuthApplicationCredentialResolver;
use App\Services\SystemRuntimeHealth;
use Illuminate\View\View;

class PlatformSystemStatusController extends Controller
{
    public function index(SystemRuntimeHealth $systemRuntimeHealth, OAuthApplicationCredentialResolver $credentialResolver): View
    {
        return view('platform.system.index', [
            ...$systemRuntimeHealth->snapshot(now()->toImmutable()),
            'legacyOAuthFallbackEnabled' => $credentialResolver->allowsLegacyFallback(),
            'legacyOAuthDependents' => $credentialResolver->workspacesRelyingOnServerCredentials(),
        ]);
    }
}
