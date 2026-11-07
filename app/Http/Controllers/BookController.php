<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
     * 登録の認可・入力検証を行う。保存はIssue 2で実装する。
     */
    public function store(StoreBookRequest $request): never
    {
        abort(501, '書籍の登録処理は実装途中です。');
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
     * 更新の認可・入力検証を行う。保存はIssue 2で実装する。
     */
    public function update(UpdateBookRequest $request, Book $book): never
    {
        abort(501, '書籍の更新処理は実装途中です。');
    }

    /**
     * 削除を認可する。削除処理はIssue 2で実装する。
     */
    public function destroy(Book $book): never
    {
        $this->authorize('delete', $book);

        abort(501, '書籍の削除処理は実装途中です。');
    }
}
