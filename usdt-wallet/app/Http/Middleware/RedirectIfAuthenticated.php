<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->user()) {
            return redirect()->route('admin.withdrawals');
        }

        return $next($request);
    }
}
