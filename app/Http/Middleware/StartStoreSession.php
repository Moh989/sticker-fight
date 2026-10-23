<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

class StartStoreSession extends StartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->is('media/*', 'catalog/*', 'admin/assets/*', 'admin/files/*', 'admin/items/*/assets/*', 'admin/items/*/art/*', 'orders/*/assets/*', 'orders/*/art/*', 'api/*')) {
            return;
        }parent::storeCurrentUrl($request, $session);
    }
}
