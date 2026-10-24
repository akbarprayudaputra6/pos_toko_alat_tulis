<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memberi setiap request sebuah kode unik (request_id) dan menempelkannya
 * ke SEMUA baris log selama request itu berjalan. Kode yang sama dikirim
 * ke klien lewat header X-Request-Id, jadi satu kejadian mudah dilacak.
 */
class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        Log::withContext([
            'request_id' => $requestId,
            'ip'         => $request->ip(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
