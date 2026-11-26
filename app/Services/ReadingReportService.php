<?php

namespace App\Services;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ReadingReportService
{
    /**
     * 指定会員の全レビューを、書籍とジャンルを先読みして取得する。
     * 認証と対象会員の選定は呼出側が担当し、取得順は保証しない。
     *
     * @param  User  $user  呼出側で認証済みと確認した保存済み会員
     * @return Collection<int, Review>
     */
    public function getReviewsFor(User $user): Collection
    {
        return $user->reviews()
            ->with('book.genres')
            ->get();
    }
}
