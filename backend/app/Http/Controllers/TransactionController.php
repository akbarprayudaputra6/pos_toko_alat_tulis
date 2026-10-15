<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function store(StoreTransactionRequest $request)
    {
        try {
            $transaction = $this->transactionService->createTransaction(
                $request->user()->id,
                $request->validated()['items']
            );

            return response()->json([
                'message' => 'Transaksi berhasil dibuat.',
                'data' => $transaction,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Transaksi gagal.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function index(Request $request)
    {
        $transactions = $this->transactionService->getAll($request->user());

        return response()->json($transactions);
    }

    public function show(Request $request, int $id)
    {
        try {
            $transaction = $this->transactionService->getDetail($request->user(), $id);

            return response()->json($transaction);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 403);
        }
    }
}
