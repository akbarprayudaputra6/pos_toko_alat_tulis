<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer',
        ]);

        $categoryId = $validated['category_id'] ?? null;

        $products = $this->productService->getAll(
            search: $request->query('search'),
            categoryId: $categoryId,
        );

        return response()->json($products);
    }

    public function store(StoreProductRequest $request)
    {
        $product = $this->productService->create($request->validated());

        return response()->json([
            'message' => 'Produk berhasil dibuat.',
            'data' => $product,
        ], 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load('category'));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $updated = $this->productService->update($product, $request->validated());

        return response()->json([
            'message' => 'Produk berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    public function destroy(Product $product)
    {
        $this->productService->delete($product);

        return response()->json([
            'message' => 'Produk berhasil dihapus.',
        ]);
    }
}
