<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexBookRequest;
use App\Http\Resources\Api\V1\BookDetailResource;
use App\Http\Resources\Api\V1\BookResource;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $book->load(['genres', 'reviews.user']);
        $book->loadAvg('reviews', 'rating');
        $book->loadCount('reviews');

        return new BookDetailResource($book);
    }

    /**
     * 書籍を登録する。
     */
    public function store(): JsonResponse
    {
        return $this->notImplemented();
    }

    /**
     * 書籍を更新する。
     */
    public function update(Book $book): JsonResponse
    {
        return $this->notImplemented();
    }

    /**
     * 書籍を削除する。
     */
    public function destroy(Book $book): JsonResponse
    {
        return $this->notImplemented();
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

    /**
     * 未実装のAPI操作に対する暫定応答。
     */
    private function notImplemented(): JsonResponse
    {
        return response()->json([
            'message' => 'このAPIは現在実装中です。',
        ], 501);
    }
}
