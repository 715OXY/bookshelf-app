<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\User;
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

        return redirect()->route('books.show', $book);
    }
}
