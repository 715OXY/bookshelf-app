<?php

namespace App\Http\Controllers;

use App\Exceptions\BookRegistrationFailedException;
use App\Http\Requests\IndexBookRequest;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class BookController extends Controller
{
    /**
     * 検索条件と並び順を適用し、書籍一覧を10件ずつ表示する。
     */
    public function index(IndexBookRequest $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Book::class);

        $filters = array_filter(
            $request->validated(),
            fn ($value): bool => $value !== null && $value !== ''
        );

        $page = filter_var(
            $request->query('page', 1),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($page === false) {
            return redirect()->route('books.index', $filters);
        }

        $query = Book::query()
            ->with('genres')
            ->withAvg('reviews', 'rating');

        if (isset($filters['keyword'])) {
            $pattern = '%'.str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                $filters['keyword']
            ).'%';

            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("author LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (isset($filters['genre'])) {
            $query->whereHas('genres', function (Builder $query) use ($filters): void {
                $query->where('genres.id', $filters['genre']);
            });
        }

        // 絞り込み後の件数で、巨大OFFSETを発行する前に補正する。
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 10));

        if ($page > $lastPage) {
            return redirect()->route(
                'books.index',
                $filters + ($lastPage === 1 ? [] : ['page' => $lastPage])
            );
        }

        $books = $this->applyIndexSort($query, $request->sortName())
            ->paginate(10, ['*'], 'page', $page)
            ->appends($filters);
        $genres = Genre::query()->orderBy('id')->get();

        return view('books.index', compact('books', 'genres'));
    }

    /**
     * 書籍登録フォームを表示する。
     */
    public function create(): View
    {
        $this->authorize('create', Book::class);

        $genres = Genre::query()->orderBy('id')->get();

        return view('books.create', compact('genres'));
    }

    /**
     * 書籍と選択ジャンルを登録する。
     */
    public function store(StoreBookRequest $request): RedirectResponse
    {
        $this->authorize('create', Book::class);

        $attributes = $request->safe()->except('genres');
        $attributes['user_id'] = $request->user()->id;
        $attributes['published_date'] = $attributes['published_date'] ?? null;
        $genreIds = $request->validated('genres');

        try {
            $book = DB::transaction(function () use ($attributes, $genreIds): Book {
                $book = Book::create($attributes);
                $book->genres()->sync($genreIds);

                return $book;
            });
        } catch (Throwable $e) {
            throw new BookRegistrationFailedException($e);
        }

        return redirect()
            ->route('books.show', $book)
            ->with('success', '書籍を登録しました。');
    }

    /**
     * 書籍と関連情報を表示する。
     */
    public function show(Book $book): View
    {
        $this->authorize('view', $book);

        $book->load([
            'genres',
            'reviews.user',
            'reviews.likedByUsers',
        ]);

        return view('books.show', compact('book'));
    }

    /**
     * 作成者に書籍編集フォームを表示する。
     */
    public function edit(Book $book): View
    {
        $this->authorize('update', $book);

        $book->load('genres');
        $genres = Genre::query()->orderBy('id')->get();

        return view('books.edit', compact('book', 'genres'));
    }

    /**
     * 書籍情報と選択ジャンルを更新する。出版日は省略なら保持し、明示NULLなら消去する。
     */
    public function update(UpdateBookRequest $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        $attributes = $request->safe()->except(['genres', 'user_id']);
        $genreIds = $request->validated('genres');

        DB::transaction(function () use ($book, $attributes, $genreIds): void {
            $book->update($attributes);
            $book->genres()->sync($genreIds);
        });

        return redirect()
            ->route('books.show', $book)
            ->with('success', '書籍情報を更新しました。');
    }

    /**
     * 作成者が書籍を削除する。
     */
    public function destroy(Book $book): RedirectResponse
    {
        $this->authorize('delete', $book);

        DB::transaction(function () use ($book): void {
            $book->delete();
        });

        return redirect()
            ->route('books.index')
            ->with('success', '書籍を削除しました。');
    }

    /**
     * 許可済みのソート名から、固定された順序だけを適用する。
     *
     * @param  Builder<Book>  $query
     * @return Builder<Book>
     */
    private function applyIndexSort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'title' => $query->orderBy('title')->orderByDesc('created_at')->orderByDesc('id'),
            'rating' => $query->orderByRaw('reviews_avg_rating IS NULL ASC')
                ->orderByDesc('reviews_avg_rating')
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
