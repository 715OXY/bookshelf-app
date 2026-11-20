<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookInvalidFormDisplayTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('不正入力のケース')]
    public function test_不正入力後も書籍フォームを描画できる(
        string $operation,
        string $field,
        mixed $value
    ): void {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $user->id,
            'isbn' => '9781234567890',
        ]);

        $book->genres()->sync([$genre->id]);

        $payload = [
            'title' => '再表示確認用タイトル',
            'author' => '再表示確認用著者',
            'isbn' => $operation === 'store'
                ? '9781234567891'
                : $book->isbn,
            'published_date' => '2026-11-01',
            'description' => '再表示確認用説明',
            'image_url' => 'https://example.com/cover.jpg',
            'genres' => [$genre->id],
        ];

        if ($field === 'genres' && $value === null) {
            unset($payload[$field]);
        } else {
            $payload[$field] = $value;
        }

        $before = $this->DB状態();

        $this->actingAs($user, 'web');

        $formUrl = $operation === 'store'
            ? route('books.create')
            : route('books.edit', $book);

        $this->from($formUrl);

        $response = $operation === 'store'
            ? $this->post(route('books.store'), $payload)
            : $this->put(route('books.update', $book), $payload);

        $response
            ->assertRedirect($formUrl)
            ->assertSessionHasErrors([$field])
            ->assertSessionMissing('success');

        $this->assertSame($before, $this->DB状態());

        $form = $this->get($formUrl)->assertOk();

        $xpath = $this->XPath($form);

        $this->assertSame(
            1,
            $xpath->query('//input[@name="title"]')->length
        );

        if ($field !== 'title') {
            $this->assertSame(
                $payload['title'],
                $xpath->query('//input[@name="title"]')
                    ->item(0)->getAttribute('value')
            );
        }

        if ($field === 'genres') {
            $this->assertSame(
                0,
                $xpath->query(
                    '//input[@name="genres[]" and @checked]'
                )->length
            );
        } else {
            $this->assertSame(
                1,
                $xpath->query(
                    '//input[@name="genres[]" and @checked]'
                )->length
            );
        }

        $this->assertSame($before, $this->DB状態());
    }

    public static function 不正入力のケース(): array
    {
        $cases = [];

        foreach (['store', 'update'] as $operation) {
            foreach ([
                'title',
                'author',
                'isbn',
                'published_date',
                'description',
                'image_url',
            ] as $field) {
                $cases[$operation.' '.$field.' 配列'] = [
                    $operation,
                    $field,
                    ['invalid'],
                ];
            }

            $cases[$operation.' ジャンル文字列'] = [
                $operation,
                'genres',
                'invalid',
            ];

            $cases[$operation.' ジャンル全解除'] = [
                $operation,
                'genres',
                null,
            ];
        }

        return $cases;
    }

    private function XPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML(
            '<?xml encoding="UTF-8">'.$response->getContent()
        );

        return new DOMXPath($document);
    }

    private function DB状態(): array
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
}
