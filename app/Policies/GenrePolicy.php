<?php

namespace App\Policies;

use App\Models\Genre;
use App\Models\User;

class GenrePolicy
{
    /**
     * 認証済み会員にジャンル一覧の閲覧を許可する。
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * 認証済み会員にジャンル詳細の閲覧を許可する。
     */
    public function view(User $user, Genre $genre): bool
    {
        return true;
    }

    /**
     * 認証済み会員にジャンル登録を許可する。
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * 認証済み会員にジャンル更新を許可する。
     */
    public function update(User $user, Genre $genre): bool
    {
        return true;
    }

    /**
     * 認証済み会員にジャンル削除を許可する。
     */
    public function delete(User $user, Genre $genre): bool
    {
        return true;
    }
}
