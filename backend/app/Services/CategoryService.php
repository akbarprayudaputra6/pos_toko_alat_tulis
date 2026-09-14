<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function getAll(): Collection
    {
        return Category::withCount('product')->get();
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);

        return $category;
    }

    public function delete(Category $category): void
    {
        if ($category->product()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Kategori tidak bisa dihapus karena masih memiliki produk terkait.'],
            ]);
        }

        $category->delete();
    }
}
