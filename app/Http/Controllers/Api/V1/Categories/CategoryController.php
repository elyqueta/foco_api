<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Categories;

use App\Actions\Categories\RemoveCategory;
use App\Actions\Categories\ResolveCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Categories\StoreCategoryRequest;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Lista as categorias do utilizador: padrão primeiro, depois ordem
     * alfabética, com contagens de tarefas e projetos (com withCount, sem N+1).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $categories = $user->categories()
            ->withCount(['tasks', 'projects'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories)->response();
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('create', Category::class);

        $name = (string) $request->validated('name');

        $category = $user->categories()->create([
            'name' => $name,
            'name_key' => Category::nameKey($name),
            'is_default' => false,
        ]);

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }

    /**
     * Remove uma categoria personalizada (o nome vem URL-encoded e é
     * resolvido por name_key). Padrão → 422 CATEGORY_PROTECTED;
     * inexistente → 404.
     */
    public function destroy(Request $request, string $name): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $category = app(ResolveCategory::class)->handle($user, $name);

        if ($category === null) {
            // Recurso inexistente (contrato: "Não encontrado.") — diferente de
            // rota inexistente (ROUTE_NOT_FOUND).
            throw new ModelNotFoundException;
        }

        $this->authorize('delete', $category);

        app(RemoveCategory::class)->handle($user, $category);

        return response()->json(['message' => 'Categoria removida.'], 200);
    }
}
