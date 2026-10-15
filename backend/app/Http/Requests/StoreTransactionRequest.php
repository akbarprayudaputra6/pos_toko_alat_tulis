<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Admin & Kasir sama-sama boleh, dibatasi oleh auth:sanctum di route
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Minimal harus ada 1 item dalam transaksi.',
            'items.min' => 'Minimal harus ada 1 item dalam transaksi.',
            'items.*.product_id.required' => 'Produk wajib dipilih.',
            'items.*.product_id.exists' => 'Produk tidak ditemukan.',
            'items.*.product_id.distinct' => 'Produk yang sama tidak boleh muncul dua kali, gabungkan jumlahnya.',
            'items.*.quantity.min' => 'Jumlah minimal 1.',
        ];
    }
}
