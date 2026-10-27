<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function getAll(): Collection
    {
        return Category::withCount('products')->get();
    }

    public function create(array $data): Category
    {
        $category = Category::create($data);

        Log::info('category.created', [
            'category_id' => $category->id,
            'name'        => $category->name,
            'by'          => Auth::id(),
        ]);

        return $category;
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);

        if ($category->wasChanged()) {
            Log::info('category.updated', [
                'category_id' => $category->id,
                'changes'     => $category->getChanges(),
                'by'          => Auth::id(),
            ]);
        }

        return $category;
    }

    public function delete(Category $category): void
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori tidak bisa dihapus karena masih memiliki produk terkait.',
            ]);
        }

        $categoryId   = $category->id;
        $categoryName = $category->name;

        $category->delete();

        Log::info('category.deleted', [
            'category_id' => $categoryId,
            'name'        => $categoryName,
            'by'          => Auth::id(),
        ]);
    }
}
