<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Auth;
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
                Auth::id(),
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

    public function index()
    {
        $transactions = auth('web')->user()->transactions()
            ->with('items.product')
            ->latest()
            ->paginate(15);

        return response()->json($transactions);
    }

    public function show(int $id)
    {
        $transaction = \App\Models\Transaction::with('items.product', 'user')
            ->findOrFail($id);

        return response()->json($transaction);
    }
}
