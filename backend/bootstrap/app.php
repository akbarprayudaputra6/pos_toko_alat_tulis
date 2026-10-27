<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RequestContext::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $status = match (true) {
                    $e instanceof ValidationException => 422,
                    $e instanceof ModelNotFoundException,
                    $e instanceof NotFoundHttpException => 404,
                    $e instanceof AuthenticationException => 401,
                    $e instanceof AuthorizationException => 403,
                    $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                    default => 500,
                };

                if ($status === 429) {
                    Log::warning('security.rate_limited', ['path' => $request->path()]);
                }
                if ($status === 403) {
                    Log::notice('security.forbidden', [
                        'path'    => $request->path(),
                        'user_id' => $request->user()?->id,
                    ]);
                }

                $message = match (true) {
                    $e instanceof ModelNotFoundException,
                    $e instanceof NotFoundHttpException => 'Data yang dicari tidak ditemukan.',
                    $e instanceof AuthenticationException => 'Silakan login terlebih dahulu.',
                    $e instanceof AuthorizationException => 'Anda tidak memiliki akses untuk aksi ini.',
                    $status === 500 => 'Terjadi kesalahan internal pada server.', // Tambahkan baris ini
                    default => $e->getMessage(),
                };
                return response()->json([
                    'message' => $message,
                ], $status);
            }
        });
    })->create();
