<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use App\Services\GoogleBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class IsbnLookupTest extends TestCase
{
    use RefreshDatabase;

    private const ISBN = '9781234567890';

    private const URL = '/books/isbn/'.self::ISBN;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_books.key' => 'fake-test-key']);
        Http::preventStrayRequests();
    }

    private function candidate(array $overrides = [], string $isbn = self::ISBN): array
    {
        return ['volumeInfo' => array_replace([
            'title' => '取得した書籍', 'authors' => ['著者A', '著者B'],
            'description' => '<b>説明</b>&amp;補足', 'publishedDate' => '2024-02-29',
            'imageLinks' => ['thumbnail' => 'https://example.com/cover.jpg'],
            'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => $isbn]],
        ], $overrides)];
    }

    private function fakeBooks(array $items): void
    {
        Http::fake(['www.googleapis.com/books/v1/volumes*' => Http::response(['totalItems' => count($items), 'items' => $items])]);
    }

    private function snapshot(): array
    {
        $tables = [];
        foreach (['users', 'books', 'genres', 'book_genres', 'reviews', 'favorites', 'review_likes'] as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $tables;
    }

    public function test_正常取得と送信内容を確認し業務データを保持する(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);
        $book = Book::factory()->create(['isbn' => self::ISBN, 'user_id' => $member->id]); // 検索時は重複を拒否しない。
        $book->genres()->attach(Genre::factory()->create());
        $review = Review::factory()->create(['book_id' => $book->id, 'user_id' => $member->id]);
        $book->favoritedByUsers()->attach($member);
        $review->likedByUsers()->attach($member);
        $before = $this->snapshot();
        $this->fakeBooks([$this->candidate()]);

        $this->get(self::URL.'?isbn=0000000000000')->assertOk()->assertExactJson([
            'title' => '取得した書籍', 'author' => '著者A / 著者B', 'description' => '説明&補足',
            'image_url' => 'https://example.com/cover.jpg', 'published_date' => '2024-02-29',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return strtok($request->url(), '?') === 'https://www.googleapis.com/books/v1/volumes'
                && $request['q'] === 'isbn:'.self::ISBN && (int) $request['maxResults'] === 10
                && $request['key'] === 'fake-test-key' && $request->method() === 'GET';
        });
        $this->assertSame($before, $this->snapshot());
    }

    public function test_固定した通信設定と任意の13桁を使用する(): void
    {
        $this->actingAs(User::factory()->create());
        $isbn = '1234567890123'; // 978/979やチェックディジットで制限しない。
        $seenOptions = null;
        Http::fake(function (Request $request, array $options) use (&$seenOptions, $isbn) {
            $seenOptions = $options;
            $this->assertSame('isbn:'.$isbn, $request['q']);
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));

            return Http::response(['totalItems' => 1, 'items' => [$this->candidate([], $isbn)]]);
        });
        $this->get('/books/isbn/'.$isbn)->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(3, $seenOptions['connect_timeout']);
        $this->assertSame(10, $seenOptions['timeout']);
        $this->assertFalse($seenOptions['allow_redirects']);
    }

    public function test_出版日項目の欠落では全項目の自動入力を中止する(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = $this->candidate();
        unset($candidate['volumeInfo']['publishedDate']);
        $this->fakeBooks([$candidate]);
        $response = $this->get(self::URL)->assertStatus(422);
        $this->assertSame(['error'], array_keys($response->json()));
        $response->assertJsonPath('error', '出版日を確認できないため自動入力しませんでした。現在の入力内容は変更していません。書籍を確認し、手入力してください。');
        Http::assertSentCount(1);
    }

    public function test_任意項目が存在しない場合は空文字を返す(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = $this->candidate();
        foreach (['title', 'authors', 'description', 'imageLinks'] as $field) {
            unset($candidate['volumeInfo'][$field]);
        }
        $this->fakeBooks([$candidate]);
        $this->get(self::URL)->assertOk()->assertExactJson([
            'title' => '', 'author' => '', 'description' => '', 'image_url' => '', 'published_date' => '2024-02-29',
        ]);
        Http::assertSentCount(1);
    }

    public function test_一致かつ自動入力可能な候補を応答順で選び項目を混合しない(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeBooks([
            $this->candidate(['title' => '不一致'], '9781234567891'),
            $this->candidate(['title' => '日付不足', 'publishedDate' => '2024']),
            $this->candidate(['title' => '上限超過', 'authors' => [str_repeat('著', 256)]]),
            $this->candidate(['title' => '採用', 'description' => null, 'imageLinks' => null]),
            $this->candidate(['title' => '後の候補']),
        ]);
        $this->get(self::URL)->assertOk()->assertJsonPath('title', '採用')->assertJsonPath('description', '')->assertJsonPath('image_url', '');
        Http::assertSentCount(1);
    }

    #[DataProvider('出版日不正')]
    public function test_出版日不足では成功データを返さない(mixed $date): void
    {
        $this->actingAs(User::factory()->create());
        $before = $this->snapshot();
        $this->fakeBooks([$this->candidate(['publishedDate' => $date])]);
        $this->get(self::URL)->assertStatus(422)->assertExactJson(['error' => '出版日を確認できないため自動入力しませんでした。現在の入力内容は変更していません。書籍を確認し、手入力してください。']);
        Http::assertSentCount(1);
        $this->assertSame($before, $this->snapshot());
    }

    public static function 出版日不正(): array
    {
        return ['欠損相当' => [null], '空' => [''], '年' => ['2024'], '年月' => ['2024-02'], '非閏年' => ['2023-02-29'], '存在しない日' => ['2024-02-30'], '日時' => ['2024-02-29T00:00:00Z'], '配列' => [['2024-02-29']], '数値' => [20240229]];
    }

    #[DataProvider('上限と欠損')]
    public function test_項目の欠損変換と上限を扱う(array $overrides, int $status, ?string $field, mixed $expected): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeBooks([$this->candidate($overrides)]);
        $response = $this->get(self::URL)->assertStatus($status);
        if ($status === 200) {
            $response->assertJsonMissingPath('error')->assertJsonPath($field, $expected);
        } else {
            $this->assertIsString($response->json('error'));
            $this->assertNotSame('', $response->json('error'));
            $response->assertJsonMissingPath('title');
        }
        Http::assertSentCount(1);
    }

    public static function 上限と欠損(): array
    {
        $url = 'https://example.com/';

        return [
            'タイトル欠損' => [['title' => null], 200, 'title', ''],
            'タイトル型不正' => [['title' => ['bad']], 200, 'title', ''],
            '著者配列型不正' => [['authors' => '著者'], 200, 'author', ''],
            '著者オブジェクト' => [['authors' => (object) ['name' => '著者']], 200, 'author', ''],
            '画像リンク型不正' => [['imageLinks' => ['https://example.com/a']], 200, 'image_url', ''],
            '著者不正' => [['authors' => ['著者', 123]], 200, 'author', ''],
            '説明不正' => [['description' => ['bad']], 200, 'description', ''],
            '画像代替' => [['imageLinks' => ['thumbnail' => 'http://example.com/a', 'smallThumbnail' => $url.'b']], 200, 'image_url', $url.'b'],
            '画像不正' => [['imageLinks' => ['thumbnail' => 'javascript:alert(1)']], 200, 'image_url', ''],
            'タイトル上限' => [['title' => str_repeat('本', 255)], 200, 'title', str_repeat('本', 255)],
            'タイトル超過' => [['title' => str_repeat('本', 256)], 422, null, null],
            '著者上限' => [['authors' => [str_repeat('著', 255)]], 200, 'author', str_repeat('著', 255)],
            '著者超過' => [['authors' => [str_repeat('著', 256)]], 422, null, null],
            '複数著者の連結後255文字' => [
                ['authors' => [str_repeat('著', 126), str_repeat('者', 126)]],
                200, 'author', str_repeat('著', 126).' / '.str_repeat('者', 126),
            ],
            '複数著者の連結後256文字' => [
                ['authors' => [str_repeat('著', 126), str_repeat('者', 127)]],
                422, null, null,
            ],
            '説明上限' => [['description' => str_repeat('説', 10000)], 200, 'description', str_repeat('説', 10000)],
            '説明超過' => [['description' => str_repeat('説', 10001)], 422, null, null],
            '画像上限' => [['imageLinks' => ['thumbnail' => $url.str_repeat('a', 2048 - strlen($url))]], 200, 'image_url', $url.str_repeat('a', 2048 - strlen($url))],
            '画像超過' => [['imageLinks' => ['thumbnail' => $url.str_repeat('a', 2049 - strlen($url))]], 422, null, null],
        ];
    }

    #[DataProvider('不正ISBN')]
    public function test_不正なパスをクエリで上書きできず通信しない(string $isbn): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/books/isbn/'.rawurlencode($isbn).'?isbn='.self::ISBN)->assertStatus(422)->assertExactJson(['error' => 'ISBNは半角数字13桁で入力してください。']);
        Http::assertNothingSent();
    }

    public static function 不正ISBN(): array
    {
        return ['12桁' => ['978123456789'], '14桁' => ['97812345678901'], '英字' => ['978123456789X'], 'ハイフン' => ['978-1234567890'], '空白' => ['978 1234567890'], '前後空白' => [' 9781234567890 '], '全角' => ['９７８１２３４５６７８９０']];
    }

    public function test_未認証と権限拒否では通信しない(): void
    {
        $this->get(self::URL)->assertStatus(401)->assertExactJson(['error' => 'ログインし直してからISBN検索を利用してください。']);
        $this->get('/books/create')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        Gate::before(fn ($user, $ability) => $ability === 'create' ? false : null);
        $this->getJson(self::URL)->assertStatus(403)->assertExactJson(['error' => 'ISBN検索を利用する権限がありません。']);
        Http::assertNothingSent();
    }

    public function test_設定不足では外部へ送信しない(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.google_books.key' => null]);
        $this->get(self::URL)->assertStatus(503)->assertJsonStructure(['error']);
        Http::assertNothingSent();
    }

    #[DataProvider('外部異常')]
    public function test_外部の異常を専用エラーへ整形する(string $body, int $upstream, int $status): void
    {
        $this->actingAs(User::factory()->create());
        $before = $this->snapshot();
        Http::fake(['www.googleapis.com/books/v1/volumes*' => Http::response($body, $upstream)]);
        $response = $this->get(self::URL)->assertStatus($status)->assertJsonStructure(['error']);
        $this->assertIsString($response->json('error'));
        $this->assertNotSame('', $response->json('error'));
        $response->assertJsonMissingPath('title')->assertDontSee('secret')->assertDontSee('fake-test-key');
        Http::assertSentCount(1);
        $this->assertSame($before, $this->snapshot());
    }

    public static function 外部異常(): array
    {
        return [
            '空候補' => ['{"totalItems":0}', 200, 404],
            '不正JSON' => ['<html>secret</html>', 200, 502],
            '配列ルート' => ['[]', 200, 502],
            '件数型不正' => ['{"totalItems":"1","items":[]}', 200, 502],
            '候補欠損' => ['{"totalItems":1}', 200, 502],
            '候補型不正' => ['{"totalItems":1,"items":{}}', 200, 502],
            '候補null' => ['{"totalItems":0,"items":null}', 200, 502],
            '候補要素不正' => ['{"totalItems":1,"items":[null]}', 200, 502],
            '書誌型不正' => ['{"totalItems":1,"items":[{"volumeInfo":[]}]}', 200, 502],
            '外部400' => ['secret', 400, 502], '外部403' => ['secret', 403, 502],
            '外部500' => ['secret', 500, 502], '外部429' => ['secret', 429, 503], 'リダイレクト' => ['secret', 302, 502],
        ];
    }

    public function test_識別番号一致を確認できない候補は採用しない(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeBooks([$this->candidate([], '9781234567891'), $this->candidate(['industryIdentifiers' => [['type' => 'ISBN_10', 'identifier' => self::ISBN]]])]);
        $this->get(self::URL)->assertStatus(404)->assertJsonMissingPath('title');
        Http::assertSentCount(1);
    }

    #[DataProvider('通信障害')]
    public function test_通信障害を503で返す(string $reason): void
    {
        $this->actingAs(User::factory()->create());
        $attempts = 0;
        Http::fake(function () use (&$attempts, $reason) {
            $attempts++;
            throw new ConnectionException($reason.' fake-test-key');
        });
        $this->get(self::URL)->assertStatus(503)->assertDontSee('fake-test-key');
        $this->assertSame(1, $attempts);
    }

    public static function 通信障害(): array
    {
        return ['接続失敗' => ['connection failed'], 'タイムアウト' => ['timeout']];
    }

    public function test_予期しない例外も内部情報なしの500にする(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(GoogleBooksService::class)->shouldReceive('lookup')->once()->with(self::ISBN)
            ->andThrow(new RuntimeException('private fake-test-key'));
        $this->get(self::URL)->assertStatus(500)->assertExactJson(['error' => '書籍情報の取得に失敗しました。']);
        Http::assertNothingSent();
    }

    public function test_両画面へ接続し取得値を登録更新して重複と所有者認可を維持する(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $genre = Genre::factory()->create();
        $this->actingAs($owner);
        $this->get('/books/create')->assertOk()->assertSee('id="isbn-search"', false)->assertSee('id="fetch-btn"', false);
        $this->fakeBooks([$this->candidate()]);
        $data = $this->get(self::URL)->assertOk()->json();
        $payload = $data + ['isbn' => self::ISBN, 'genres' => [$genre->id]];
        $this->post(route('books.store'), $payload)->assertSessionHasNoErrors();
        $book = Book::where('isbn', self::ISBN)->firstOrFail();
        $this->assertSame($owner->id, $book->user_id);
        $this->assertSame($data['title'], $book->title);
        $this->assertSame($data['author'], $book->author);
        $this->assertSame($data['description'], $book->description);
        $this->assertSame($data['image_url'], $book->image_url);
        $this->assertSame($data['published_date'], $book->published_date->format('Y-m-d'));
        $this->assertSame([$genre->id], $book->genres->modelKeys());
        $this->get(route('books.edit', $book))->assertOk()->assertSee('id="isbn-search"', false);
        $payload['title'] = '手入力で補完';
        $this->put(route('books.update', $book), $payload)->assertRedirect(route('books.show', $book))->assertSessionHasNoErrors();
        $this->assertSame('手入力で補完', $book->fresh()->title);
        $otherBook = Book::factory()->create(['user_id' => $owner->id]);
        $before = $this->snapshot();
        $invalidPayload = $payload;
        $invalidPayload['published_date'] = '';
        $this->put(route('books.update', $book), $invalidPayload)->assertSessionHasErrors('published_date');
        $this->assertSame($before, $this->snapshot());
        $this->put(route('books.update', $otherBook), $payload)->assertSessionHasErrors('isbn');
        $this->assertSame($before, $this->snapshot());
        $this->post(route('books.store'), $payload)->assertSessionHasErrors('isbn');
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($other)->put(route('books.update', $book), $payload)->assertForbidden();
        $this->get(route('books.edit', $book))->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }
}
