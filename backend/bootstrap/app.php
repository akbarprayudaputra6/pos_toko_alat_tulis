<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
                    default => 500,
                };

                $message = match (true) {
                    $e instanceof ModelNotFoundException,
                    $e instanceof NotFoundHttpException => 'Data yang dicari tidak ditemukan.',
                    $e instanceof AuthenticationException => 'Silakan login terlebih dahulu.',
                    $e instanceof AuthorizationException => 'Anda tidak memiliki akses untuk aksi ini.',
                    $status === 500 => 'Terjadi kesalahan pada server.',
                    default => $e->getMessage(),
                };

                return response()->json([
                    'message' => $message,
                ], $status);
            }
        });
    })->create();
