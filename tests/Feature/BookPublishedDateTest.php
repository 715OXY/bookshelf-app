<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookPublishedDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_web登録のjson要求で不正日付を検証エラーにして保存しない(): void
    {
        $owner = User::factory()->create();
        $genres = Genre::factory()->count(2)->create();
        $this->actingAs($owner, 'web');
        $payload = $this->payload($genres->modelKeys());
        $payload['published_date'] = '2026-02-30';
        $before = $this->snapshot();

        // 公開APIではなく、Web登録ルートのFormRequestにJSON応答を要求する。
        $this->postJson(route('books.store'), $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonValidationErrors('published_date')
            ->assertJsonPath('errors.published_date.0', '出版日は有効な日付形式で入力してください。')
            ->assertSessionMissing('_old_input');

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
        $this->assertSame($before, $this->snapshot());
        $this->assertAuthenticatedAs($owner, 'web');
        Http::assertNothingSent();
    }

    #[DataProvider('登録の出版日')]
    public function test_webと_ap_iで出版日を任意入力として保存し読取へ反映する(bool $api, bool $omitted, mixed $date, ?string $expected): void
    {
        $owner = User::factory()->create();
        $genres = Genre::factory()->count(2)->create();
        $this->authenticate($owner, $api);
        $payload = $this->payload($genres->modelKeys());

        if (! $omitted) {
            $payload['published_date'] = $date;
        }

        $response = $this->submit($api, 'POST', $payload);
        $book = Book::query()->sole();
        $this->assertWriteResponse($response, $api, $book, $expected, true);
        $this->assertSame($expected, $book->published_date?->format('Y-m-d'));
        $this->assertSame($owner->id, $book->user_id);
        $this->assertEqualsCanonicalizing($genres->modelKeys(), $book->genres->modelKeys());

        $this->getJson(route('api.v1.books.index', ['keyword' => $payload['isbn']]))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data' => [['published_date']]])
            ->assertJsonPath('data.0.published_date', $expected);
        $this->getJson(route('api.v1.books.show', $book))->assertOk()
            ->assertJsonStructure(['data' => ['published_date']])
            ->assertJsonPath('data.published_date', $expected);

        if ($expected === null) {
            $detail = $this->get(route('books.show', $book))->assertOk();
            $this->assertSame('出版日: 未登録', $this->xpath($detail)->evaluate('normalize-space(//p[strong[contains(., "出版日")]])'));
            $this->actingAs($owner, 'web');
            $edit = $this->get(route('books.edit', $book))->assertOk();
            $field = $this->xpath($edit)->query('//input[@name="published_date"]')->item(0);
            $this->assertNotNull($field);
            $this->assertSame('', $field->getAttribute('value'));
            $edit->assertSeeText('出版日'); // 必須マークの制約は変更しない。
        }
    }

    public static function 登録の出版日(): array
    {
        $cases = [];

        foreach ([false, true] as $api) {
            foreach ([
                '省略' => [true, null, null],
                '空欄' => [false, '', null],
                '空白' => [false, '   ', null],
                '明示NULL' => [false, null, null],
                '有効日付' => [false, '2024-02-29', '2024-02-29'],
            ] as $name => [$omitted, $date, $expected]) {
                $cases[($api ? 'API・' : 'Web・').$name] = [$api, $omitted, $date, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('更新の出版日')]
    public function test_更新で省略は保持し明示した空値だけを_nul_lにして関連データを保つ(bool $api, ?string $initial, bool $omitted, mixed $date, ?string $expected): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create([
            'user_id' => $owner->id, 'published_date' => $initial,
            'description' => '既存の説明', 'image_url' => 'https://example.com/old.jpg',
        ]);
        $newGenre = Genre::factory()->create();
        $review = Review::factory()->create(['book_id' => $book->id]);
        $book->favoritedByUsers()->attach($owner);
        $review->likedByUsers()->attach($owner);
        $this->authenticate($owner, $api);
        $before = $this->snapshot();
        $payload = $this->payload([$newGenre->id], $book->isbn);

        if (! $omitted) {
            $payload['published_date'] = $date;
        }

        $response = $this->submit($api, 'PUT', $payload, $book);
        $this->assertWriteResponse($response, $api, $book, $expected);
        $fresh = $book->fresh();
        $this->assertSame($expected, $fresh->published_date?->format('Y-m-d'));
        $this->assertSame($owner->id, $fresh->user_id);
        $this->assertSame([$newGenre->id], $fresh->genres->modelKeys());
        $this->assertSame($api ? null : '既存の説明', $fresh->description);
        $this->assertSame($api ? null : 'https://example.com/old.jpg', $fresh->image_url);
        $after = $this->snapshot();

        foreach (['users', 'genres', 'reviews', 'favorites', 'review_likes'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
    }

    public static function 更新の出版日(): array
    {
        $cases = [];

        foreach (self::登録の出版日() as $name => [$api, $omitted, $date, $expected]) {
            foreach ([null, '2026-11-01'] as $initial) {
                $cases[$name.'・初期値'.($initial ?? 'NULL')] = [
                    $api, $initial, $omitted, $date, $omitted ? $initial : $expected,
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('NULL書籍の不正日付')]
    public function test_nul_l書籍でも不正な日付を拒否し業務7テーブルを保つ(bool $api, mixed $date, string $message): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id, 'published_date' => null]);
        $this->authenticate($owner, $api);
        $payload = $this->payload($book->genres->modelKeys(), $book->isbn);
        $payload['published_date'] = $date;
        $before = $this->snapshot();
        $response = $this->submit($api, 'PUT', $payload, $book);

        if ($api) {
            $response->assertUnprocessable()->assertJsonPath('errors.published_date.0', $message);
        } else {
            $response->assertRedirect(route('books.edit', $book))->assertSessionHasErrors(['published_date' => $message]);
        }

        $this->assertSame($before, $this->snapshot());
    }

    public static function NULL書籍の不正日付(): array
    {
        $cases = [];

        foreach ([false, true] as $api) {
            $cases[($api ? 'API' : 'Web').'・配列'] = [$api, ['invalid'], '出版日は文字列で入力してください。'];
            $cases[($api ? 'API' : 'Web').'・不正日付'] = [$api, '2026-02-30', '出版日は有効な日付形式で入力してください。'];
        }

        return $cases;
    }

    #[DataProvider('操作経路')]
    public function test_nul_l書籍も所有者以外は更新できない(bool $api): void
    {
        $book = Book::factory()->create(['published_date' => null]);
        $this->authenticate(User::factory()->create(), $api);
        $payload = $this->payload($book->genres->modelKeys(), $book->isbn);
        $payload['published_date'] = null;
        $before = $this->snapshot();

        $this->submit($api, 'PUT', $payload, $book)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public static function 操作経路(): array
    {
        return ['Web' => [false], 'API' => [true]];
    }

    private function authenticate(User $user, bool $api): void
    {
        if ($api) {
            $this->withToken($user->createToken('issue-79-test', ['*'], now()->addHour())->plainTextToken);
        } else {
            $this->actingAs($user, 'web');
        }
    }

    /** @param array<int, int> $genreIds */
    private function payload(array $genreIds, string $isbn = '9781234567890'): array
    {
        return ['title' => '出版日任意入力の書籍', 'author' => 'テスト著者', 'isbn' => $isbn, 'genres' => $genreIds];
    }

    private function submit(bool $api, string $method, array $payload, ?Book $book = null): TestResponse
    {
        $name = ($api ? 'api.v1.books.' : 'books.').($method === 'POST' ? 'store' : 'update');
        $url = route($name, $book === null ? [] : ['book' => $book]);

        return $api ? $this->json($method, $url, $payload) : $this->call($method, $url, $payload);
    }

    private function assertWriteResponse(TestResponse $response, bool $api, Book $book, ?string $date, bool $created = false): void
    {
        if ($api) {
            $response->assertStatus($created ? 201 : 200)
                ->assertJsonStructure(['data' => ['published_date']])
                ->assertJsonPath('data.published_date', $date);
        } else {
            $response->assertRedirect(route('books.show', $book))->assertSessionHasNoErrors();
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];

        foreach (['users', 'books', 'genres', 'book_genres', 'reviews', 'favorites', 'review_likes'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }
}
