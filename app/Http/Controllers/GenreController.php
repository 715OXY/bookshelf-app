<?php

namespace App\Http\Controllers;

use App\Models\Genre;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class GenreController extends Controller
{
    /**
     * ジャンル一覧と書籍数を表示する。
     */
    public function index(): View
    {
        $this->authorize('viewAny', Genre::class);

        $genres = Genre::query()
            ->withCount('books')
            ->orderBy('id')
            ->get();

        return view('genres.index', compact('genres'));
    }

    /**
     * ジャンル詳細と所属書籍を表示する。
     */
    public function show(Genre $genre): View
    {
        $this->authorize('view', $genre);

        $books = $genre->books()
            ->with('genres')
            ->orderByDesc('books.created_at')
            ->orderByDesc('books.id')
            ->paginate(10);

        return view('genres.show', compact('genre', 'books'));
    }

    /**
     * ジャンル登録フォームを表示する。
     */
    public function create(): View
    {
        $this->authorize('create', Genre::class);

        return view('genres.create');
    }

    /**
     * ジャンルを登録する。
     *
     * 登録処理はIssue #34で実装する。
     */
    public function store(): RedirectResponse
    {
        $this->authorize('create', Genre::class);

        abort(501, 'ジャンル登録は未実装です。');
    }

    /**
     * ジャンル編集フォームを表示する。
     */
    public function edit(Genre $genre): View
    {
        $this->authorize('update', $genre);

        return view('genres.edit', compact('genre'));
    }

    /**
     * ジャンルを更新する。
     *
     * 更新処理はIssue #34で実装する。
     */
    public function update(Genre $genre): RedirectResponse
    {
        $this->authorize('update', $genre);

        abort(501, 'ジャンル更新は未実装です。');
    }

    /**
     * ジャンルを削除する。
     *
     * 削除処理はIssue #34で実装する。
     */
    public function destroy(Genre $genre): RedirectResponse
    {
        $this->authorize('delete', $genre);

        abort(501, 'ジャンル削除は未実装です。');
    }
}
