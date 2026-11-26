<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupIsbnRequest;
use App\Models\Book;
use App\Services\GoogleBooksService;
use Illuminate\Http\JsonResponse;

class IsbnLookupController extends Controller
{
    public function __invoke(LookupIsbnRequest $request, GoogleBooksService $service): JsonResponse
    {
        $this->authorize('create', Book::class);

        return response()->json($service->lookup($request->validated('isbn')));
    }
}
