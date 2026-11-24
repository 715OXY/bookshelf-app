<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexBookRequest;
use App\Http\Requests\Api\V1\StoreBookRequest;
use App\Http\Requests\Api\V1\UpdateBookRequest;
use App\Http\Resources\Api\V1\BookDetailResource;
use App\Http\Resources\Api\V1\BookResource;
use App\Models\Book;
use App\Services\Api\V1\BookWriteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

class BookController extends Controller
{
    /**
     * 書籍一覧を取得する。
     */
    public function index(IndexBookRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $keyword = $validated['keyword'] ?? null;
        $genreId = isset($validated['genre_id'])
            ? (int) $validated['genre_id']
            : null;
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = $this->buildIndexQuery($keyword, $genreId);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $books = $total > 0 && $page <= $lastPage
            ? $query->forPage($page, $perPage)->get()
            : collect();

        $paginator = new LengthAwarePaginator(
            $books,
            $total,
            $perPage,
            $page,
            ['path' => $request->url()]
        );

        $paginator->appends(
            $request->safe()->only(['keyword', 'genre_id', 'per_page'])
        );

        return BookResource::collection($paginator);
    }

    /**
     * 書籍詳細を取得する。
     */
    public function show(Book $book): BookDetailResource
    {
        return $this->detailResource($book);
    }

    /**
     * 書籍を登録する。
     */
    public function store(
        StoreBookRequest $request,
        BookWriteService $service
    ): JsonResponse {
        $attributes = $request->safe()->except(['genres', 'user_id']);
        $attributes['user_id'] = $request->user()->getAuthIdentifier();
        $attributes['description'] = $attributes['description'] ?? null;
        $attributes['image_url'] = $attributes['image_url'] ?? null;

        $book = $service->store(
            $attributes,
            $request->validated('genres')
        );

        return $this->detailResource($book)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 書籍を更新する。
     */
    public function update(
        UpdateBookRequest $request,
        Book $book,
        BookWriteService $service
    ): JsonResponse {
        $attributes = $request->safe()->except(['genres', 'user_id']);
        $attributes['description'] = $attributes['description'] ?? null;
        $attributes['image_url'] = $attributes['image_url'] ?? null;

        $book = $service->update(
            $book,
            $attributes,
            $request->validated('genres')
        );

        return $this->detailResource($book)
            ->response()
            ->setStatusCode(200);
    }

    /**
     * 書籍を削除する。
     */
    public function destroy(
        Book $book,
        BookWriteService $service
    ): Response {
        $service->destroy($book);

        return response()->noContent();
    }

    /**
     * 書籍詳細の関連情報を取得してResourceを生成する。
     */
    private function detailResource(Book $book): BookDetailResource
    {
        $book->load(['genres', 'reviews.user']);
        $book->loadAvg('reviews', 'rating');
        $book->loadCount('reviews');

        return new BookDetailResource($book);
    }

    /**
     * 検索条件を適用した書籍一覧取得用クエリを構築する。
     *
     * @return Builder<Book>
     */
    private function buildIndexQuery(
        ?string $keyword,
        ?int $genreId
    ): Builder {
        $query = Book::query()
            ->with('genres')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews');

        if ($keyword !== null && $keyword !== '') {
            $pattern = '%'.str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $keyword
            ).'%';

            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("author LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("isbn LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if ($genreId !== null) {
            $query->whereHas(
                'genres',
                function (Builder $query) use ($genreId): void {
                    $query->where('genres.id', $genreId);
                }
            );
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
