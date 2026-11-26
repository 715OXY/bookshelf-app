<?php

namespace App\Services;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

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

    /**
     * 取得済みレビューから、基本サマリーと評価分布の部分集計結果を構築する。
     * 入力はgetReviewsFor()で取得した、指定会員本人の全レビューとする。
     * 対象会員の選定と本人限定取得は呼出側および取得基盤の責務であり、
     * 本メソッドはHTTP認証・会員の再選別を行わない。会員混在の入力は契約違反となる。
     *
     * @param  Collection<int, Review>  $reviews
     * @return array{
     *     summary: array{total_reviews: int, books_read: int, average_rating: float},
     *     rating_distribution: SupportCollection<int, int>
     * }
     */
    public function getBasicStats(Collection $reviews): array
    {
        return [
            'summary' => $this->buildSummary($reviews),
            'rating_distribution' => $this->buildRatingDistribution($reviews),
        ];
    }

    /**
     * 全レビューの件数・平均と、重複を除いた読了冊数を算出する。
     * 0件時の0.0は平均未定義の内部代替値であり、有効な評価点ではない。
     *
     * @param  Collection<int, Review>  $reviews
     * @return array{total_reviews: int, books_read: int, average_rating: float}
     */
    private function buildSummary(Collection $reviews): array
    {
        return [
            'total_reviews' => $reviews->count(),
            'books_read' => $reviews->pluck('book_id')->unique()->count(),
            'average_rating' => (float) ($reviews->avg('rating') ?? 0.0),
        ];
    }

    /**
     * 星1〜5のレビュー件数を、欠損区分を0で補完した順序付きCollectionで返す。
     *
     * @param  Collection<int, Review>  $reviews
     * @return SupportCollection<int, int>
     */
    private function buildRatingDistribution(Collection $reviews): SupportCollection
    {
        $counts = $reviews->pluck('rating')->countBy();

        return (new SupportCollection([1, 2, 3, 4, 5]))
            ->map(fn (int $rating): int => $counts->get($rating, 0))
            ->values();
    }
}
