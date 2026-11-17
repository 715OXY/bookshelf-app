<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InteractionScreenIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_本人のお気に入りだけを10冊ずつ描画し詳細とページのリンクを表示する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);
        $books = Book::factory()->count(11)->create();
        $user->favoriteBooks()->attach($books->modelKeys(), [
            'created_at' => '2026-11-01 12:00:00',
            'updated_at' => '2026-11-01 12:00:00',
        ]);
        $otherBook = Book::factory()->create(['title' => '他会員だけのお気に入り書籍']);
        $otherUser->favoriteBooks()->attach($otherBook->id);
        $expected = $books->sortByDesc('id')->values();

        foreach ([1, 2] as $page) {
            $response = $this->get(route('favorites.index', ['page' => $page]))->assertOk();
            $response->assertDontSeeText($otherBook->title);
            $actualLinks = [];
            foreach ($this->XPath($response)->query('//h3/a') as $node) {
                $actualLinks[] = $node->getAttribute('href');
            }
            $displayed = $expected->slice(($page - 1) * 10, 10)->values();
            $this->assertSame(
                $displayed->map(fn (Book $book): string => route('books.show', $book))->all(),
                $actualLinks
            );
            foreach ($displayed as $book) {
                $response->assertSee('action="'.route('favorites.toggle', $book).'"', false);
            }
            $targetPage = $page === 1 ? 2 : 1;
            $response->assertSee('href="'.route('favorites.index', ['page' => $targetPage]).'"', false);
        }
    }

    public function test_お気に入り一覧で最後の書籍を解除すると0件表示となり他会員分は残る(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create(['title' => 'お気に入り解除確認書籍']);
        $user->favoriteBooks()->attach($book->id);
        $otherUser->favoriteBooks()->attach($book->id);
        $this->actingAs($user);

        $this->get(route('books.show', $book))->assertOk()
            ->assertSee('title="お気に入りから削除"', false);
        $this->from(route('favorites.index'))->post(route('favorites.toggle', $book))
            ->assertRedirect(route('favorites.index'));
        $this->get(route('favorites.index'))->assertOk()
            ->assertSeeText('お気に入りに登録された書籍はありません。')
            ->assertSee('href="'.route('books.index').'"', false)
            ->assertDontSeeText($book->title);

        // 実リクエスト同様に会員を再取得し、既読のリレーションを持ち越さない。
        $this->actingAs($user->fresh());
        $this->get(route('books.show', $book))->assertOk()
            ->assertSee('title="お気に入りに追加"', false)
            ->assertDontSee('title="お気に入りから削除"', false);
        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'book_id' => $book->id]);
        $this->assertDatabaseHas('favorites', ['user_id' => $otherUser->id, 'book_id' => $book->id]);
    }

    public function test_いいね追加解除後に書籍詳細の登録状態と件数が変わる(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $otherUser->id,
        ]);
        $otherUser->likedReviews()->attach($review->id);
        $this->actingAs($user);
        $this->get(route('books.show', $book))->assertOk()->assertSeeText('いいね (1)');

        foreach ([true, false] as $registered) {
            $this->post(route('reviews.like', $review))
                ->assertRedirect(route('books.show', $book));
            $this->actingAs($user->fresh());
            $response = $this->get(route('books.show', $book))->assertOk();
            $response->assertSeeText($registered ? 'いいね済み (2)' : 'いいね (1)');
            if (! $registered) {
                $response->assertDontSeeText('いいね済み');
            }
        }
        $this->assertDatabaseHas('review_likes', ['user_id' => $otherUser->id, 'review_id' => $review->id]);
        $this->assertDatabaseMissing('review_likes', ['user_id' => $user->id, 'review_id' => $review->id]);
    }

    public function test_ランキングに平均評価順の書籍と集計値と詳細リンクを表示する(): void
    {
        $higher = Book::factory()->create(['title' => 'ランキング上位確認書籍']);
        $lower = Book::factory()->create(['title' => 'ランキング下位確認書籍']);
        $withoutReview = Book::factory()->create(['title' => 'レビューなし除外確認書籍']);
        foreach ([4, 5] as $rating) {
            Review::factory()->create(['book_id' => $higher->id, 'rating' => $rating]);
        }
        Review::factory()->create(['book_id' => $lower->id, 'rating' => 3]);

        $response = $this->get(route('ranking.index'))->assertOk();
        $response->assertSeeText('4.50')->assertSeeText('3.00')
            ->assertSeeText('(2件のレビュー)')->assertSeeText('(1件のレビュー)')
            ->assertDontSeeText($withoutReview->title);
        $actualLinks = [];
        foreach ($this->XPath($response)->query('//a[.//h3]') as $node) {
            $actualLinks[] = $node->getAttribute('href');
        }
        $this->assertSame([
            route('books.show', $higher),
            route('books.show', $lower),
        ], $actualLinks);
        $this->get(route('books.show', $higher))->assertOk()->assertSeeText($higher->title);
    }

    public function test_レビュー0件のランキングに空の案内を表示する(): void
    {
        Book::factory()->create();

        $this->get(route('ranking.index'))->assertOk()
            ->assertSeeText('まだレビューが投稿された書籍がありません。');
    }

    private function XPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }
}
