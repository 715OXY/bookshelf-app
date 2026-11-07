<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_登録の必須項目に日本語エラーを返す(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->post(route('books.store'), [])
            ->assertRedirect(route('books.create'))
            ->assertSessionHasErrors([
                'title' => 'タイトルを入力してください。',
                'author' => '著者を入力してください。',
                'isbn' => 'ISBNを入力してください。',
                'published_date' => '出版日を入力してください。',
                'genres' => 'ジャンルを1つ以上選択してください。',
            ]);

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
    }

    #[DataProvider('不正な入力')]
    public function test_不正な登録入力を拒否する(
        string $field,
        mixed $value,
        string $errorKey,
        string $message
    ): void {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $payload = $this->正常入力($genre);
        $payload[$field] = $value;

        $this->actingAs($user, 'web')
            ->post(route('books.store'), $payload)
            ->assertRedirect(route('books.create'))
            ->assertSessionHasErrors([$errorKey => $message]);

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
    }

    public static function 不正な入力(): array
    {
        return [
            'タイトルが配列' => [
                'title',
                ['不正な値'],
                'title',
                'タイトルは文字列で入力してください。',
            ],
            'タイトルが256文字' => [
                'title',
                str_repeat('あ', 256),
                'title',
                'タイトルは255文字以下で入力してください。',
            ],
            '著者が256文字' => [
                'author',
                str_repeat('あ', 256),
                'author',
                '著者は255文字以下で入力してください。',
            ],
            'ISBNが12桁' => [
                'isbn',
                '978123456789',
                'isbn',
                'ISBNは半角数字13桁で入力してください。',
            ],
            'ISBNが14桁' => [
                'isbn',
                '97812345678901',
                'isbn',
                'ISBNは半角数字13桁で入力してください。',
            ],
            'ISBNに英字を含む' => [
                'isbn',
                '978123456789X',
                'isbn',
                'ISBNは半角数字13桁で入力してください。',
            ],
            '存在しない日付' => [
                'published_date',
                '2026-02-30',
                'published_date',
                '出版日は有効な日付をYYYY-MM-DD形式で入力してください。',
            ],
            '説明が配列' => [
                'description',
                ['不正な値'],
                'description',
                '説明は文字列で入力してください。',
            ],
            '説明が上限超過' => [
                'description',
                str_repeat('あ', 10001),
                'description',
                '説明は10,000文字以下で入力してください。',
            ],
            '画像URLが形式不正' => [
                'image_url',
                'not-a-url',
                'image_url',
                '画像URLは有効なURL形式で入力してください。',
            ],
            'ジャンルが空配列' => [
                'genres',
                [],
                'genres',
                'ジャンルを1つ以上選択してください。',
            ],
            'ジャンルが文字列' => [
                'genres',
                'invalid',
                'genres',
                'ジャンルの選択形式が正しくありません。',
            ],
            'ジャンルIDが整数でない' => [
                'genres',
                ['invalid'],
                'genres.0',
                'ジャンルの選択値が正しくありません。',
            ],
            'ジャンルIDが存在しない' => [
                'genres',
                [2147483647],
                'genres.0',
                '選択したジャンルは存在しません。選び直してください。',
            ],
        ];
    }

    public function test_登録失敗時に入力を保持する(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $payload = $this->正常入力($genre);
        $payload['isbn'] = 'invalid';

        $this->actingAs($user, 'web')
            ->post(route('books.store'), $payload)
            ->assertRedirect(route('books.create'))
            ->assertSessionHasErrors('isbn')
            ->assertSessionHasInput('title', $payload['title'])
            ->assertSessionHasInput('author', $payload['author'])
            ->assertSessionHasInput('isbn', 'invalid')
            ->assertSessionHasInput('genres', $payload['genres']);

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
    }

    public function test_登録済み_isb_nを拒否する(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        Book::factory()->create([
            'user_id' => $user->id,
            'isbn' => '9781234567890',
        ]);

        $before = $this->書籍関連のDB状態();

        $this->actingAs($user, 'web')
            ->post(route('books.store'), $this->正常入力($genre))
            ->assertRedirect(route('books.create'))
            ->assertSessionHasErrors([
                'isbn' => 'このISBNは既に登録されています。',
            ]);

        $this->assertDatabaseCount('books', 1);
        $this->assertSame($before, $this->書籍関連のDB状態());
    }

    public function test_更新で他の書籍の_isb_nを拒否し既存データを保持する(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $user->id,
            'isbn' => '9781234567890',
        ]);
        $book->genres()->sync([$genre->id]);

        $otherBook = Book::factory()->create([
            'user_id' => $user->id,
            'isbn' => '9781234567891',
        ]);

        $before = $this->書籍関連のDB状態();

        $payload = $this->正常入力($genre);
        $payload['isbn'] = $otherBook->isbn;

        $this->actingAs($user, 'web')
            ->put(route('books.update', $book), $payload)
            ->assertRedirect(route('books.edit', $book))
            ->assertSessionHasErrors([
                'isbn' => 'このISBNは既に登録されています。',
            ])
            ->assertSessionHasInput('isbn', $otherBook->isbn);

        $this->assertDatabaseCount('books', 2);
        $this->assertSame($before, $this->書籍関連のDB状態());

        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $genre->id,
        ]);
    }

    /**
     * 書籍とジャンル紐付けのDB保存値を取得する。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function 書籍関連のDB状態(): array
    {
        $snapshot = [];

        foreach (['books', 'book_genres'] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function 正常入力(Genre $genre): array
    {
        return [
            'title' => '入力検証用の書籍',
            'author' => 'テスト著者',
            'isbn' => '9781234567890',
            'published_date' => '2026-11-01',
            'description' => null,
            'image_url' => null,
            'genres' => [$genre->id],
        ];
    }
}
