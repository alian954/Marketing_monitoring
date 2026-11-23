<?php

namespace App\Models;

trait ScopesThroughAuthenticatedMonitor
{
    protected static function bootScopesThroughAuthenticatedMonitor(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('monitor'));
    }
}
