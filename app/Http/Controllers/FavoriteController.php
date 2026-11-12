<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    /**
     * 認証ユーザーのお気に入りを追加・解除する。
     */
    public function toggle(Request $request, Book $book): RedirectResponse
    {
        $userId = $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($userId, $book): void {
            // 同じユーザーのお気に入り操作を順番に処理する。
            $user = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            $favorites = $user->favoriteBooks();

            if ($favorites->whereKey($book->getKey())->exists()) {
                $user->favoriteBooks()->detach($book->getKey());

                return;
            }

            $user->favoriteBooks()->attach($book->getKey());
        });

        if ($this->isFromFavorites($request)) {
            return redirect()->route('favorites.index');
        }

        return redirect()->route('books.show', $book);
    }

    /**
     * 認証ユーザーのお気に入り書籍を10件ずつ表示する。
     */
    public function index(Request $request): View|RedirectResponse
    {
        $page = filter_var(
            $request->query('page', 1),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($page === false) {
            return redirect()->route('favorites.index');
        }

        $query = $request->user()->favoriteBooks()
            ->orderByDesc('favorites.created_at')
            ->orderByDesc('books.id');

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 10));

        if ($page > $lastPage) {
            return redirect()->route(
                'favorites.index',
                $lastPage === 1 ? [] : ['page' => $lastPage]
            );
        }

        $books = $query->paginate(10, ['books.*'], 'page', $page);

        return view('favorites.index', compact('books'));
    }

    /**
     * 同一オリジンのお気に入り一覧からの操作か判定する。
     */
    private function isFromFavorites(Request $request): bool
    {
        $referer = $request->headers->get('referer');

        if ($referer === null) {
            return false;
        }

        $parts = parse_url($referer);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme === $request->getScheme()
            && $host === strtolower($request->getHost())
            && $port === $request->getPort()
            && ($parts['path'] ?? '') === '/favorites';
    }
}
