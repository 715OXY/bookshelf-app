<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenreRequest;
use App\Http\Requests\UpdateGenreRequest;
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
     * 検証済みのジャンル名を登録する。
     */
    public function store(StoreGenreRequest $request): RedirectResponse
    {
        Genre::create($request->validated());

        return redirect()
            ->route('genres.index')
            ->with('success', 'ジャンルを登録しました。');
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
     * 検証済みのジャンル名を更新する。
     */
    public function update(
        UpdateGenreRequest $request,
        Genre $genre
    ): RedirectResponse {
        $genre->update($request->validated());

        return redirect()
            ->route('genres.index')
            ->with('success', 'ジャンルを更新しました。');
    }

    /**
     * 書籍が紐付いていないジャンルを削除する。
     */
    public function destroy(Genre $genre): RedirectResponse
    {
        $this->authorize('delete', $genre);

        if ($genre->books()->exists()) {
            return redirect()
                ->route('genres.index')
                ->with(
                    'error',
                    '書籍が紐付いているため、このジャンルは削除できません。'
                );
        }

        $genre->delete();

        return redirect()
            ->route('genres.index')
            ->with('success', 'ジャンルを削除しました。');
    }
}
