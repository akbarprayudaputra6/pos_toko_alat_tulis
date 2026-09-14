<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    public function index()
    {
        $categories = $this->categoryService->getAll();

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request)
    {
        $category = $this->categoryService->create($request->validated());

        return response()->json([
            'message' => 'Kategori berhasil dibuat.',
            'data' => $category,
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $updated = $this->categoryService->update($category, $request->validated());

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    public function destroy(Category $category)
    {
        $this->categoryService->delete($category);

        return response()->json([
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }
}
