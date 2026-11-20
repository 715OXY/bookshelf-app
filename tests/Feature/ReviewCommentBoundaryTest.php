<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewCommentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('コメント未入力')]
    public function test_コメント未入力でもレビューを投稿できる(
        array $input
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user, 'web')
            ->post(route('reviews.store', $book), $input)
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'レビューを投稿しました。');

        $this->assertDatabaseCount('reviews', 1);

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 5,
            'comment' => null,
        ]);
    }

    #[DataProvider('投稿更新の操作')]
    public function test_コメントの最大文字数を保存できる(
        string $operation
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $comment = str_repeat('感', 10000);

        $this->actingAs($user, 'web');

        $payload = [
            'rating' => 5,
            'comment' => $comment,
        ];

        if ($operation === 'store') {
            $response = $this->post(
                route('reviews.store', $book),
                $payload
            );

            $review = Review::query()->sole();
        } else {
            $review = Review::factory()
                ->for($user)
                ->for($book)
                ->create(['rating' => 1, 'comment' => null]);

            $response = $this->put(
                route('reviews.update', $review),
                $payload
            );

            $review = $review->fresh();
        }

        $response->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame($user->id, $review->user_id);
        $this->assertSame($book->id, $review->book_id);
        $this->assertSame(5, $review->rating);
        $this->assertSame(10000, mb_strlen($review->comment));
        $this->assertSame($comment, $review->comment);
    }

    #[DataProvider('投稿更新の操作')]
    public function test_コメントの上限超過を拒否する(
        string $operation
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create();

        $before = $review->fresh()->getRawOriginal();
        $comment = str_repeat('感', 10001);

        $payload = [
            'rating' => 5,
            'comment' => $comment,
        ];

        $this->actingAs($user, 'web');

        $response = $operation === 'store'
            ? $this->post(route('reviews.store', $book), $payload)
            : $this->put(route('reviews.update', $review), $payload);

        $response->assertRedirect(
            $operation === 'store'
                ? route('books.show', $book)
                : route('reviews.edit', $review)
        )
            ->assertSessionHasErrors([
                'comment' => 'コメントは10,000文字以内で入力してください。',
            ])
            ->assertSessionHasInput('comment', $comment)
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame($before, $review->fresh()->getRawOriginal());
    }

    #[DataProvider('コメント未入力')]
    public function test_コメント必須エラー後に編集画面を再表示する(
        array $input
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create([
                'rating' => 2,
                'comment' => '保存済みコメント',
            ]);

        $before = $review->fresh()->getRawOriginal();
        $url = route('reviews.edit', $review);

        $this->actingAs($user, 'web')
            ->put(route('reviews.update', $review), $input)
            ->assertRedirect($url)
            ->assertSessionHasErrors([
                'comment' => 'コメントは必須です。',
            ])
            ->assertSessionMissing('success');

        $response = $this->get($url)
            ->assertOk()
            ->assertSeeText('コメントは必須です。');

        $document = new DOMDocument;
        @$document->loadHTML(
            '<?xml encoding="UTF-8">'.$response->getContent()
        );

        $xpath = new DOMXPath($document);

        $this->assertSame(
            '',
            $xpath->evaluate('string(//textarea[@name="comment"])')
        );

        $this->assertSame(
            '5',
            $xpath->evaluate(
                'string(//input[@name="rating" and @checked]/@value)'
            )
        );

        $this->assertSame($before, $review->fresh()->getRawOriginal());
    }

    public static function コメント未入力(): array
    {
        return [
            '未送信' => [['rating' => '5']],
            'NULL' => [['rating' => '5', 'comment' => null]],
            '空文字' => [['rating' => '5', 'comment' => '']],
            '空白のみ' => [['rating' => '5', 'comment' => '   ']],
        ];
    }

    public static function 投稿更新の操作(): array
    {
        return [
            '投稿' => ['store'],
            '更新' => ['update'],
        ];
    }
}
