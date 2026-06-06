<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\JwtHelper;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class JwtAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Authorization header missing or invalid'], 401);
        }

        $secret = env('JWT_SECRET');
        $payload = JwtHelper::verify($token, $secret);
        
        if (!$payload) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $user = User::find($payload['userId']);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'Account is inactive'], 401);
        }

        // Bind user to the Laravel Auth guard context
        Auth::setUser($user);

        // Role check
        if (!empty($roles)) {
            if (!in_array($user->role, $roles)) {
                return response()->json(['error' => 'Insufficient permissions'], 403);
            }
        }

        return $next($request);
    }
}
