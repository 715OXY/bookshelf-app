<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookController extends Controller
{
    /**
     * 書籍一覧を最新順・10件ずつ表示する。
     */
    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Book::class);

        $page = filter_var(
            $request->query('page', 1),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($page === false) {
            return redirect()->route('books.index');
        }

        $query = Book::query()
            ->with('genres')
            ->withAvg('reviews', 'rating')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // 大きすぎるページ番号も、検索前に補正する。
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 10));

        if ($page > $lastPage) {
            return redirect()->route(
                'books.index',
                $lastPage === 1 ? [] : ['page' => $lastPage]
            );
        }

        $books = $query->paginate(10, ['*'], 'page', $page);

        return view('books.index', compact('books'));
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
        $attributes = $request->safe()->except('genres');
        $attributes['user_id'] = $request->user()->id;
        $genreIds = $request->validated('genres');

        $book = DB::transaction(function () use ($attributes, $genreIds): Book {
            $book = Book::create($attributes);
            $book->genres()->sync($genreIds);

            return $book;
        });

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
     * 書籍情報と選択ジャンルを更新する。
     */
    public function update(UpdateBookRequest $request, Book $book): RedirectResponse
    {
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
}
