<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

class BookPolicy
{
    /**
     * ゲストを含め、書籍一覧の閲覧を許可する。
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * ゲストを含め、書籍詳細の閲覧を許可する。
     */
    public function view(?User $user, Book $book): bool
    {
        return true;
    }

    /**
     * 認証済み会員に書籍登録を許可する。
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * 書籍の作成者に更新を許可する。
     */
    public function update(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }

    /**
     * 書籍の作成者に削除を許可する。
     */
    public function delete(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }
}
