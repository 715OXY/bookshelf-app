<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());

        $result = $connection->selectOne(
            'SELECT DATABASE() AS database_name'
        );

        $this->assertSame('testing', $result->database_name);
    }

    /**
     * @dataProvider invalidInputProvider
     */
    public function test_投稿失敗時は書籍詳細へ戻り入力を保持して保存しない(
        array $input,
        string $field,
        string $message,
        array $oldInput
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('books.show', $book))
            ->post(route('reviews.store', $book), $input)
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasErrors([$field => $message])
            ->assertSessionHas('_old_input.rating', $oldInput['rating'])
            ->assertSessionHas('_old_input.comment', $oldInput['comment'])
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_likes', 0);
    }

    /**
     * @dataProvider invalidInputProvider
     */
    public function test_更新失敗時は編集画面へ戻り入力と既存データを保持する(
        array $input,
        string $field,
        string $message,
        array $oldInput
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create([
                'rating' => 2,
                'comment' => '変更前のコメント',
            ]);

        $user->likedReviews()->attach($review->id);

        $reviewBefore = DB::table('reviews')
            ->where('id', $review->id)
            ->first();

        $likesBefore = DB::table('review_likes')
            ->where('review_id', $review->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->actingAs($user)
            ->from(route('reviews.edit', $review))
            ->put(route('reviews.update', $review), $input)
            ->assertRedirect(route('reviews.edit', $review))
            ->assertSessionHasErrors([$field => $message])
            ->assertSessionHas(
                '_old_input.rating',
                fn ($value): bool => $value === $oldInput['rating']
            )
            ->assertSessionHas(
                '_old_input.comment',
                fn ($value): bool => $value === $oldInput['comment']
            )
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('reviews', 1);

        $this->assertEquals(
            $reviewBefore,
            DB::table('reviews')->where('id', $review->id)->first()
        );

        $this->assertEquals(
            $likesBefore,
            DB::table('review_likes')
                ->where('review_id', $review->id)
                ->orderBy('id')
                ->get()
                ->toArray()
        );
    }

    /**
     * @return array<string, array{array, string, string, array}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            '評価の範囲外' => [
                ['rating' => '6', 'comment' => '今回の入力'],
                'rating',
                '評価は1〜5で選択してください。',
                ['rating' => '6', 'comment' => '今回の入力'],
            ],
            '評価が配列' => [
                ['rating' => ['5'], 'comment' => '今回の入力'],
                'rating',
                '評価は整数で指定してください。',
                ['rating' => '', 'comment' => '今回の入力'],
            ],
            'コメントが配列' => [
                ['rating' => '5', 'comment' => ['感想']],
                'comment',
                'コメントは文字列で入力してください。',
                ['rating' => '5', 'comment' => ''],
            ],
            '評価未選択でコメントを消去' => [
                ['comment' => ''],
                'rating',
                '評価を選択してください。',
                ['rating' => '', 'comment' => ''],
            ],
        ];
    }
}
