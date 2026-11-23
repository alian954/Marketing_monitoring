<?php

namespace App\Models;

trait ScopesThroughAuthenticatedIncident
{
    protected static function bootScopesThroughAuthenticatedIncident(): void
    {
        static::addGlobalScope(new AuthenticatedRelationScope('incident.monitor'));
    }
}
