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

class ReviewScreenIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_書籍とレビューの編集削除導線はそれぞれの本人だけに表示する(): void
    {
        $bookOwner = User::factory()->create();
        $reviewer = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $bookOwner->id]);
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $reviewer->id,
        ]);

        foreach ([null, $bookOwner, $reviewer, $other] as $viewer) {
            if ($viewer !== null) {
                $this->actingAs($viewer);
            }
            $response = $this->get(route('books.show', $book))->assertOk();
            $bookEdit = 'href="'.route('books.edit', $book).'"';
            $bookDelete = 'action="'.route('books.destroy', $book).'"';
            $reviewEdit = 'href="'.route('reviews.edit', $review).'"';
            $reviewDelete = 'action="'.route('reviews.destroy', $review).'"';

            if ($viewer?->id === $bookOwner->id) {
                $response->assertSee($bookEdit, false)->assertSee($bookDelete, false);
            } else {
                $response->assertDontSee($bookEdit, false)->assertDontSee($bookDelete, false);
            }
            if ($viewer?->id === $reviewer->id) {
                $response->assertSee($reviewEdit, false)->assertSee($reviewDelete, false);
            } else {
                $response->assertDontSee($reviewEdit, false)->assertDontSee($reviewDelete, false);
            }
            $postAction = 'action="'.route('reviews.store', $book).'"';
            if ($viewer === null) {
                $response->assertDontSee($postAction, false)
                    ->assertSee('href="'.route('login').'"', false);
            } else {
                $response->assertSee($postAction, false);
            }
        }
    }

    public function test_レビュー編集の初期値と更新エラー後の入力を表示する(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = Book::factory()->create();
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $user->id,
            'rating' => 4,
            'comment' => '保存済みコメント',
        ]);
        $before = $review->fresh()->getRawOriginal();

        $initial = $this->get(route('reviews.edit', $review))->assertOk();
        $initial->assertSeeText($book->title)
            ->assertSee('action="'.route('reviews.update', $review).'"', false)
            ->assertSee('href="'.route('books.show', $book).'"', false);
        $this->assertSame('保存済みコメント', $this->XPath($initial)->evaluate('string(//textarea[@name="comment"])'));
        $this->assertSame('4', $this->XPath($initial)->evaluate('string(//input[@name="rating" and @checked]/@value)'));

        $this->put(route('reviews.update', $review), [
            'rating' => '',
            'comment' => '更新エラー後に保持するコメント',
        ])->assertRedirect(route('reviews.edit', $review))->assertSessionHasErrors('rating');

        $response = $this->get(route('reviews.edit', $review))->assertOk();
        $response->assertSeeText('評価を選択してください。');
        $xpath = $this->XPath($response);
        $this->assertSame('更新エラー後に保持するコメント', $xpath->evaluate('string(//textarea[@name="comment"])'));
        $this->assertSame(0, $xpath->query('//input[@name="rating" and @checked]')->length);
        $this->assertSame($before, $review->fresh()->getRawOriginal());
    }

    public function test_レビュー投稿エラー後にコメントと項目エラーを表示する(): void
    {
        $this->actingAs(User::factory()->create());
        $book = Book::factory()->create();

        $this->post(route('reviews.store', $book), [
            'rating' => '',
            'comment' => '投稿エラー後に保持するコメント',
        ])->assertRedirect(route('books.show', $book))->assertSessionHasErrors('rating');

        $response = $this->get(route('books.show', $book))->assertOk();
        $response->assertSeeText('評価を選択してください。');
        $xpath = $this->XPath($response);
        $this->assertSame('投稿エラー後に保持するコメント', $xpath->evaluate('string(//textarea[@name="comment"])'));
        $this->assertSame('', $xpath->evaluate('string(//select[@name="rating"]/option[@selected]/@value)'));
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_レビュー投稿更新削除後に書籍詳細へ通知と結果を表示する(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = Book::factory()->create();

        $this->post(route('reviews.store', $book), [
            'rating' => 4,
            'comment' => '投稿結果確認コメント',
        ])->assertRedirect(route('books.show', $book));
        $this->get(route('books.show', $book))->assertOk()
            ->assertSeeText('レビューを投稿しました。')
            ->assertSeeText('投稿結果確認コメント');
        $review = Review::where('book_id', $book->id)->where('user_id', $user->id)->firstOrFail();

        $this->put(route('reviews.update', $review), [
            'rating' => 5,
            'comment' => '更新結果確認コメント',
        ])->assertRedirect(route('books.show', $book));
        $this->get(route('books.show', $book))->assertOk()
            ->assertSeeText('レビューを更新しました。')
            ->assertSeeText('更新結果確認コメント')
            ->assertDontSeeText('投稿結果確認コメント');

        $this->delete(route('reviews.destroy', $review))
            ->assertRedirect(route('books.show', $book));
        $this->get(route('books.show', $book))->assertOk()
            ->assertSeeText('レビューを削除しました。')
            ->assertSeeText('まだレビューはありません。')
            ->assertDontSeeText('更新結果確認コメント');
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    private function XPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }
}
