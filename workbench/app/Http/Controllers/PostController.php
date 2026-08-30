<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Ifds\TenantGuard\Facades\TenantGuard;
use Workbench\App\Models\Post;

/**
 * Note the total absence of a where() clause: the isolation comes entirely
 * from the middleware plus the global scope.
 */
class PostController
{
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'tenant' => TenantGuard::id(),
            'count' => Post::count(),
            'titles' => Post::query()->orderBy('id')->pluck('title'),
        ]);
    }

    public function store(): JsonResponse
    {
        $post = Post::create([
            'title' => request('title', 'Untitled'),
            'slug' => request('slug', 'untitled-'.uniqid()),
        ]);

        return new JsonResponse(['id' => $post->id, 'tenant' => $post->tenant_id], 201);
    }
}
