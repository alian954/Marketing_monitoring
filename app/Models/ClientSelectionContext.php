<?php

namespace App\Models;

use Illuminate\Http\Request;

class ClientSelectionContext
{
    private const REQUEST_ATTRIBUTE = 'selected_client_id';

    public static function select(Request $request, ?int $clientId): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $clientId);
    }

    public static function selectedClientId(): ?int
    {
        $clientId = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_int($clientId) && $clientId > 0 ? $clientId : null;
    }
}
