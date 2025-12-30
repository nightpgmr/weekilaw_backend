<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight OPTIONS requests
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200)
                ->header('Access-Control-Allow-Origin', 'http://localhost:3002')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization, Accept, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        // Add CORS headers to all responses
        $response->headers->set('Access-Control-Allow-Origin', 'http://localhost:3002');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization, Accept, X-CSRF-TOKEN');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}
