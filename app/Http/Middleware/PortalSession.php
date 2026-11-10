<?php

namespace App\Http\Middleware;

use App\Portal\Auth;
use App\Portal\Ctx;
use App\Portal\Lang;
use App\Portal\PortalError;
use Closure;
use Illuminate\Http\Request;

/* Every page needs a signed-in person; the saved language wins after sign-in. */
class PortalSession
{
    public function handle(Request $request, Closure $next)
    {
        $s = Auth::sessionFrom($request->cookie(Auth::COOKIE));
        if (! $s) {
            throw new PortalError(401, 'SIGNED_OUT', 'Your session has ended. Sign in again.');
        }
        Ctx::set(Ctx::from($s['session'], $s['user'], $request));
        Lang::set($s['user']->language ?: 'en');

        return $next($request);
    }
}
