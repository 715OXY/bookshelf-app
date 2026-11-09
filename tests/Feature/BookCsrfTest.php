<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            VerifyCsrfToken::class,
            EnforcedBookCsrfMiddleware::class
        );
    }

    #[DataProvider('トークン不備の操作')]
    public function test_トークン不備では書籍関連データを変更しない(
        string $method,
        string $path,
        ?string $submittedToken
    ): void {
        [$owner, $book, $payload] = $this->操作の準備();

        $before = $this->DB状態();

        if ($submittedToken !== null) {
            $payload['_token'] = $submittedToken;
        }

        $this->actingAs($owner, 'web')
            ->withSession([
                '_token' => 'expected-book-csrf-token',
                'csrf_probe' => 'preserved',
            ])
            ->call($method, sprintf($path, $book->id), $payload)
            ->assertStatus(419)
            ->assertSessionMissing('success')
            ->assertSessionHas('csrf_probe', 'preserved');

        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertSame($before, $this->DB状態());
    }

    public static function トークン不備の操作(): array
    {
        return [
            '登録でトークンなし' => ['POST', '/books', null],
            '登録で不一致' => ['POST', '/books', 'wrong-token'],
            '更新でトークンなし' => ['PUT', '/books/%d', null],
            '更新で不一致' => ['PUT', '/books/%d', 'wrong-token'],
            '削除でトークンなし' => ['DELETE', '/books/%d', null],
            '削除で不一致' => ['DELETE', '/books/%d', 'wrong-token'],
        ];
    }

    #[DataProvider('有効トークンの操作')]
    public function test_有効トークンで書籍操作ができる(
        string $method,
        string $path,
        string $message
    ): void {
        [$owner, $book, $payload] = $this->操作の準備();

        $payload['_token'] = 'expected-book-csrf-token';

        $response = $this->actingAs($owner, 'web')
            ->withSession([
                '_token' => 'expected-book-csrf-token',
            ])
            ->call($method, sprintf($path, $book->id), $payload);

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', $message);

        if ($method === 'DELETE') {
            $response->assertRedirect(route('books.index'));

            $this->assertDatabaseMissing('books', [
                'id' => $book->id,
            ]);
            $this->assertDatabaseMissing('book_genres', [
                'book_id' => $book->id,
            ]);
        } else {
            $savedBook = Book::query()
                ->where('isbn', $payload['isbn'])
                ->firstOrFail();

            $response->assertRedirect(route('books.show', $savedBook));

            $this->assertSame($owner->id, $savedBook->user_id);
            $this->assertSame($payload['title'], $savedBook->title);

            $this->assertEqualsCanonicalizing(
                $payload['genres'],
                $savedBook->genres()->pluck('genres.id')->all()
            );

            if ($method === 'POST') {
                $this->assertDatabaseCount('books', 2);
                $this->assertNotSame($book->id, $savedBook->id);
            } else {
                $this->assertDatabaseCount('books', 1);
                $this->assertSame($book->id, $savedBook->id);
            }
        }

        $this->assertAuthenticatedAs($owner, 'web');
    }

    public static function 有効トークンの操作(): array
    {
        return [
            '登録' => [
                'POST',
                '/books',
                '書籍を登録しました。',
            ],
            '更新' => [
                'PUT',
                '/books/%d',
                '書籍情報を更新しました。',
            ],
            '削除' => [
                'DELETE',
                '/books/%d',
                '書籍を削除しました。',
            ],
        ];
    }

    /**
     * @return array{0: User, 1: Book, 2: array<string, mixed>}
     */
    private function 操作の準備(): array
    {
        $owner = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'isbn' => '9781234567890',
        ]);
        $book->genres()->sync([$genre->id]);

        return [
            $owner,
            $book,
            [
                'title' => 'CSRF検証用の書籍',
                'author' => 'テスト著者',
                'isbn' => '9781234567892',
                'published_date' => '2026-11-01',
                'description' => null,
                'image_url' => null,
                'genres' => [$genre->id],
            ],
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function DB状態(): array
    {
        $snapshot = [];

        foreach ([
            'users',
            'books',
            'genres',
            'reviews',
            'book_genres',
            'favorites',
            'review_likes',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}

class EnforcedBookCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
