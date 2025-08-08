<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimit
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $key = $this->resolveRequestSignature($request);
        
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $maxAttempts) {
            \Log::warning('Rate limit excedido', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'route' => $request->route() ? $request->route()->getName() : 'unknown',
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Muitas requisições. Tente novamente em alguns minutos.',
                'retry_after' => $decayMinutes * 60
            ], 429);
        }
        
        Cache::put($key, $attempts + 1, now()->addMinutes($decayMinutes));
        
        $response = $next($request);
        
        // Adicionar headers de rate limit
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - $attempts - 1));
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($decayMinutes)->timestamp);
        
        return $response;
    }

    /**
     * Resolve a unique signature for the request
     */
    protected function resolveRequestSignature(Request $request): string
    {
        $userId = null;
        
        try {
            $user = auth('api')->user();
            $userId = $user ? $user->id : null;
        } catch (\Exception $e) {
            // Ignore authentication errors for rate limiting
        }
        
        if ($userId) {
            return 'api_rate_limit:user:' . $userId;
        }
        
        return 'api_rate_limit:ip:' . $request->ip();
    }
}