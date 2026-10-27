<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function getAll(?string $search = null, ?int $categoryId = null): Collection
    {
        return Product::with('category')
            ->when($search, fn($query) => $query->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%"))
            ->when($categoryId, fn($query) => $query->where('category_id', $categoryId))
            ->get();
    }

    public function create(array $data): Product
    {
        if (empty($data['sku'])) {
            $data['sku'] = $this->resolveSku($data);
        }

        $product = Product::create($data);

        Log::info('product.created', [
            'product_id' => $product->id,
            'by' => Auth::id()
        ]);

        return $product;
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        if ($product->wasChanged('price')) {
            Log::info('product.price_changed', [
                'product_id' => $product->id,
                'from'       => $product->getOriginal('price'),
                'to'         => $product->price,
                'by'         => Auth::id(),
            ]);
        }

        return $product->load('category');
    }

    public function delete(Product $product): void
    {
        $product->delete();

        Log::info('product.deleted', ['product_id' => $product->id, 'by' => Auth::id()]);
    }

    // SKU
    protected function resolveSku(array $data): string
    {
        // Kalau user input SKU manual -> validasi keunikannya
        if (!empty($data['sku'])) {
            $this->ensureSkuUnique($data['sku']);
            return strtoupper(trim($data['sku']));
        }

        // Kalau kosong -> generate otomatis
        return $this->generateSku($data['category_id'], $data['name']);
    }

    protected function generateSku(int $categoryId, string $name): string
    {
        $category = Category::findOrFail($categoryId);
        $prefix = Str::upper(Str::substr(Str::slug($category->name), 0, 3)); // e.g. "MIN" dari "Minuman"

        // Ambil nomor urut terakhir untuk kategori ini
        $lastNumber = Product::where('category_id', $categoryId)
            ->where('sku', 'like', "{$prefix}-%")
            ->count();

        $nextNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        $sku = "{$prefix}-{$nextNumber}";

        // Jaga-jaga kalau ternyata sudah ada (race condition/counting gap)
        while (Product::where('sku', $sku)->exists()) {
            $nextNumber = str_pad((int) $nextNumber + 1, 4, '0', STR_PAD_LEFT);
            $sku = "{$prefix}-{$nextNumber}";
        }

        return $sku;
    }

    protected function ensureSkuUnique(string $sku): void
    {
        if (Product::where('sku', strtoupper(trim($sku)))->exists()) {
            throw ValidationException::withMessages([
                'sku' => 'SKU sudah digunakan oleh produk lain.',
            ]);
        }
    }
}
