<?php

namespace App\Services;

use App\Models\Genre;
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
     * 取得済みの本人レビューから、基本統計・評価分布・両TOP5の4統計を統合する。
     * 入力はgetReviewsFor()による本人全レビューで、bookとbook.genresが先読み済みとする。
     * 認証・対象会員選定は呼出側、本人限定取得はgetReviewsFor()の責務とする。
     * 再取得・会員の再選別は行わず、入力Collection・Modelを変更しない。
     *
     * @param  Collection<int, Review>  $reviews
     * @return array{
     *     summary: array{total_reviews: int, books_read: int, average_rating: float},
     *     rating_distribution: SupportCollection<int, int>,
     *     top_rated_books: list<array{id: int, title: string, author: string, rating: int}>,
     *     genre_ratings: list<array{id: int, name: string, count: int, average_rating: float}>
     * }
     */
    public function getStats(Collection $reviews): array
    {
        return [
            ...$this->getBasicStats($reviews),
            'top_rated_books' => $this->buildTopRatedBooks($reviews),
            'genre_ratings' => $this->buildGenreRatings($reviews),
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

    /**
     * 本人レビューを書籍ID単位で集約し、最高評価4以上の書籍を最大5件返す。
     * 代表評価降順・書籍IDの数値昇順で並べ、先読み済みbookの表示情報を使う。
     * 入力はgetReviewsFor()による本人の全レビューで、bookとbook.genresが先読み済みとする。
     *
     * @param  Collection<int, Review>  $reviews
     * @return list<array{id: int, title: string, author: string, rating: int}>
     */
    private function buildTopRatedBooks(Collection $reviews): array
    {
        return $reviews->toBase()
            ->groupBy(fn (Review $review): int => (int) $review->book_id)
            ->map(function (SupportCollection $bookReviews): array {
                $rating = (int) $bookReviews->max('rating');
                $book = $bookReviews->first()->getRelation('book');

                return [
                    'id' => (int) $book->id,
                    'title' => (string) $book->title,
                    'author' => (string) $book->author,
                    'rating' => $rating,
                ];
            })
            ->filter(fn (array $book): bool => $book['rating'] >= 4)
            ->sort(fn (array $left, array $right): int => ($right['rating'] <=> $left['rating'])
                ?: ($left['id'] <=> $right['id']))
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * 本人の各レビューを所属ジャンルごとに1件ずつ計上し、平均評価傾向を最大5件返す。
     * 複数所属では各ジャンルへ計上し、未所属では行を生成しない。
     * 未丸め平均降順・レビュー件数降順・ジャンルIDの数値昇順で並べる。
     * 入力はgetReviewsFor()による本人の全レビューで、bookとbook.genresが先読み済みとする。
     *
     * @param  Collection<int, Review>  $reviews
     * @return list<array{id: int, name: string, count: int, average_rating: float}>
     */
    private function buildGenreRatings(Collection $reviews): array
    {
        return $reviews->toBase()
            ->flatMap(function (Review $review): SupportCollection {
                $genres = $review->getRelation('book')->getRelation('genres');

                return $genres->toBase()
                    ->map(fn (Genre $genre): array => [
                        'id' => (int) $genre->id,
                        'name' => (string) $genre->name,
                        'rating' => $review->rating,
                    ]);
            })
            ->groupBy('id')
            ->map(fn (SupportCollection $genreReviews): array => [
                'id' => (int) $genreReviews->first()['id'],
                'name' => (string) $genreReviews->first()['name'],
                'count' => $genreReviews->count(),
                'average_rating' => (float) $genreReviews->avg('rating'),
            ])
            ->sort(fn (array $left, array $right): int => ($right['average_rating'] <=> $left['average_rating'])
                ?: ($right['count'] <=> $left['count'])
                ?: ($left['id'] <=> $right['id']))
            ->take(5)
            ->values()
            ->all();
    }
}
