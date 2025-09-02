<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAutoRefresh
{
    public function handle(Request $request, Closure $next): Response
    {
        // Por enquanto, apenas passar adiante
        return $next($request);
    }
}