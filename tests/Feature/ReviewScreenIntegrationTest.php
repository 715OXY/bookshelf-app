<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $response->assertSeeText('評価は必須です。');
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
        $response->assertSeeText('評価は必須です。');
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

    public function test_投稿後に入力欄が初期化され各操作の通知は次の閲覧に残らない(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = Book::factory()->create();
        $detailUrl = route('books.show', $book);

        // 一度入力エラーを起こし、旧入力がある状態を経由する。
        $this->post(route('reviews.store', $book), [
            'rating' => '',
            'comment' => 'エラー時に保持するコメント',
        ])->assertRedirect($detailUrl)->assertSessionHasErrors('rating');

        $this->get($detailUrl)->assertOk()
            ->assertSeeText('エラー時に保持するコメント');

        $this->post(route('reviews.store', $book), [
            'rating' => 4,
            'comment' => '正常に投稿したコメント',
        ])->assertRedirect($detailUrl)->assertSessionHasNoErrors();

        $posted = $this->get($detailUrl)->assertOk()
            ->assertSeeText('レビューを投稿しました。')
            ->assertSeeText('正常に投稿したコメント')
            ->assertDontSeeText('評価は必須です。')
            ->assertDontSeeText('エラー時に保持するコメント');

        $xpath = $this->XPath($posted);

        $this->assertSame(
            '',
            $xpath->evaluate('string(//textarea[@name="comment"])')
        );
        $this->assertSame(
            0,
            $xpath->query(
                '//select[@name="rating"]/option[@selected and @value!=""]'
            )->length
        );
        $this->assertSame(
            '',
            $xpath->evaluate(
                'string(//select[@name="rating"]/option[1]/@value)'
            )
        );

        $this->get($detailUrl)->assertOk()
            ->assertDontSeeText('レビューを投稿しました。');

        $review = Review::query()
            ->where('book_id', $book->id)
            ->where('user_id', $user->id)
            ->sole();

        $this->put(route('reviews.update', $review), [
            'rating' => 5,
            'comment' => '正常に更新したコメント',
        ])->assertRedirect($detailUrl)->assertSessionHasNoErrors();

        $this->get($detailUrl)->assertOk()
            ->assertSeeText('レビューを更新しました。')
            ->assertSeeText('正常に更新したコメント');

        $this->get($detailUrl)->assertOk()
            ->assertDontSeeText('レビューを更新しました。');

        $this->delete(route('reviews.destroy', $review))
            ->assertRedirect($detailUrl);

        $this->get($detailUrl)->assertOk()
            ->assertSeeText('レビューを削除しました。');

        $this->get($detailUrl)->assertOk()
            ->assertDontSeeText('レビューを削除しました。');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_コメントの特殊文字は詳細と編集とエラー後の再表示で文字列として扱われる(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = Book::factory()->create();

        $comment = '<b data-review-probe="1">感想</b> & "引用"';

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $user->id,
            'rating' => 4,
            'comment' => $comment,
        ]);

        $detail = $this->get(route('books.show', $book))->assertOk();
        $detail->assertSee(e($comment), false)
            ->assertDontSee($comment, false);

        $this->assertSame(
            0,
            $this->XPath($detail)->query('//*[@data-review-probe]')->length
        );

        $edit = $this->get(route('reviews.edit', $review))->assertOk();

        $this->assertSame(
            $comment,
            $this->XPath($edit)->evaluate('string(//textarea[@name="comment"])')
        );
        $edit->assertSee(e($comment), false)
            ->assertDontSee($comment, false);

        $retryComment = '</textarea><b data-review-probe="2">再入力</b> & "引用"';

        $this->put(route('reviews.update', $review), [
            'rating' => '',
            'comment' => $retryComment,
        ])->assertRedirect(route('reviews.edit', $review))
            ->assertSessionHasErrors('rating');

        $retry = $this->get(route('reviews.edit', $review))->assertOk();
        $retry->assertSeeText('評価は必須です。')
            ->assertSee(e($retryComment), false)
            ->assertDontSee($retryComment, false);

        $xpath = $this->XPath($retry);

        $this->assertSame(
            $retryComment,
            $xpath->evaluate('string(//textarea[@name="comment"])')
        );
        $this->assertSame(
            0,
            $xpath->query('//*[@data-review-probe]')->length
        );

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 4,
            'comment' => $comment,
        ]);
    }

    #[DataProvider('不正な配列入力')]
    public function test_配列入力を拒否して画面を再表示し既存データを保持する(
        string $method,
        string $invalidField
    ): void {
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

        $payload = [
            'rating' => '5',
            'comment' => '保持するコメント',
        ];
        $payload[$invalidField] = ['不正な配列'];

        $submitUrl = $method === 'POST'
            ? route('reviews.store', $book)
            : route('reviews.update', $review);

        $returnUrl = $method === 'POST'
            ? route('books.show', $book)
            : route('reviews.edit', $review);

        $message = $invalidField === 'rating'
            ? '評価は整数で入力してください。'
            : 'コメントは文字列で入力してください。';

        $this->from($returnUrl)
            ->call($method, $submitUrl, $payload)
            ->assertRedirect($returnUrl)
            ->assertSessionHasErrors([$invalidField => $message])
            ->assertSessionMissing('success');

        $response = $this->get($returnUrl)->assertOk();
        $response->assertSeeText($message);

        $xpath = $this->XPath($response);

        $this->assertSame(
            $invalidField === 'comment' ? '' : '保持するコメント',
            $xpath->evaluate('string(//textarea[@name="comment"])')
        );

        $ratingPath = $method === 'POST'
            ? 'string(//select[@name="rating"]/option[@selected]/@value)'
            : 'string(//input[@name="rating" and @checked]/@value)';

        $this->assertSame(
            $invalidField === 'rating' ? '' : '5',
            $xpath->evaluate($ratingPath)
        );

        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame($before, $review->fresh()->getRawOriginal());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function 不正な配列入力(): array
    {
        return [
            '投稿の評価が配列' => ['POST', 'rating'],
            '投稿のコメントが配列' => ['POST', 'comment'],
            '更新の評価が配列' => ['PUT', 'rating'],
            '更新のコメントが配列' => ['PUT', 'comment'],
        ];
    }

    private function XPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }
}
