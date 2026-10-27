<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()->role !== $role) {
            Log::notice('security.forbidden', [
                'user_id' => $request->user()?->id,
                'path'    => $request->path(),
            ]);

            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk aksi ini.',
            ], 403);
        }

        return $next($request);
    }
}
