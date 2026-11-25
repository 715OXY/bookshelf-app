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

class BookIndexSearchTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('検索対象')]
    public function test_ゲストと会員がタイトルまたは著者を部分一致検索できる(bool $authenticated, string $keyword): void
    {
        if ($authenticated) {
            $this->actingAs(User::factory()->create(), 'web');
        }

        $book = Book::factory()->create(['title' => 'Laravel実践入門', 'author' => '山田太郎']);
        Book::factory()->create(['title' => 'PHP基礎講座', 'author' => '鈴木花子']);

        $response = $this->get($this->URL(['keyword' => $keyword]))->assertOk();
        $this->一覧IDを確認($response, [$book->id]);
        $response->assertSeeText('検索結果: 1件');
        $this->assertSame($keyword, $this->XPath($response)->query('//input[@name="keyword"]')->item(0)->getAttribute('value'));
    }

    public static function 検索対象(): array
    {
        return [
            'ゲストのタイトル検索' => [false, '実践'],
            'ゲストの著者検索' => [false, '山田'],
            '会員のタイトル検索' => [true, '実践'],
            '会員の著者検索' => [true, '山田'],
        ];
    }

    public function test_ジャンル単独とキーワードとの_an_d条件で絞り込む(): void
    {
        $genre = Genre::factory()->create();
        $otherGenre = Genre::factory()->create();
        $matched = Book::factory()->create(['title' => 'Laravel入門', 'author' => '山田太郎']);
        $matched->genres()->sync([$genre->id, $otherGenre->id]);
        $authorOnly = Book::factory()->create(['title' => 'PHP入門', 'author' => 'Laravel研究会']);
        $authorOnly->genres()->sync([$otherGenre->id]);
        $genreOnly = Book::factory()->create(['title' => 'DB入門', 'author' => '鈴木花子']);
        $genreOnly->genres()->sync([$genre->id]);

        $response = $this->get($this->URL(['genre' => $genre->id]))->assertOk();
        $this->一覧IDを確認($response, [$genreOnly->id, $matched->id]);

        $response = $this->get($this->URL(['keyword' => 'Laravel', 'genre' => $genre->id]))->assertOk();
        $this->一覧IDを確認($response, [$matched->id]);
        $this->assertSame((string) $genre->id, $this->XPath($response)->query('//select[@name="genre"]/option[@selected]')->item(0)->getAttribute('value'));
    }

    #[DataProvider('空の条件')]
    public function test_条件なしと空欄では通常の一覧を表示する(array $filters): void
    {
        $book = Book::factory()->create();
        $response = $this->get($this->URL($filters))->assertOk();
        $this->一覧IDを確認($response, [$book->id]);
        $response->assertDontSeeText('検索結果:');
        $this->assertSame(1, $this->XPath($response)->query('//form[@method="GET"]//input[@name="keyword"]')->length);
    }

    public static function 空の条件(): array
    {
        return [
            '未指定' => [[]],
            '空文字' => [['keyword' => '', 'genre' => '']],
            '半角空白' => [['keyword' => '   ', 'genre' => '   ']],
        ];
    }

    public function test_isb_nのみの一致は検索対象に含めず0件を通常表示する(): void
    {
        Book::factory()->create(['title' => 'PHP入門', 'author' => '山田太郎', 'isbn' => '9781234567890']);
        $response = $this->get($this->URL(['keyword' => '123456']))->assertOk();
        $this->一覧IDを確認($response, []);
        $response->assertSeeText('検索結果: 0件')->assertSeeText('書籍が見つかりませんでした。')->assertSessionMissing('error');
    }

    public function test_未登録の一覧も提供_bladeの既存文言で表示する(): void
    {
        $this->get(route('books.index'))->assertOk()
            ->assertSeeText('書籍が見つかりませんでした。')
            ->assertDontSeeText('検索結果:')->assertSessionMissing('error');
    }

    #[DataProvider('文字として検索する条件')]
    public function test_特殊文字を文字として検索する(string $keyword, string $matchedTitle, string $otherTitle): void
    {
        $book = Book::factory()->create(['title' => $matchedTitle, 'author' => '山田太郎']);
        Book::factory()->create(['title' => $otherTitle, 'author' => '鈴木花子']);
        $this->一覧IDを確認($this->get($this->URL(['keyword' => $keyword]))->assertOk(), [$book->id]);
    }

    public static function 文字として検索する条件(): array
    {
        return [
            'パーセント' => ['%', '100%理解するPHP', '100点を目指すPHP'],
            'アンダースコア' => ['_', 'PHP_basic', 'PHP-basic'],
            '感嘆符' => ['!', 'PHP!', 'PHP'],
            'バックスラッシュ' => ['\\', 'PHP\\入門', 'PHP入門'],
            '引用符' => ["' OR 1=1 --", "PHP ' OR 1=1 -- 入門", 'PHP入門'],
        ];
    }

    public function test_255文字のキーワードとゼロを検索できる(): void
    {
        $book = Book::factory()->create(['title' => str_repeat('あ', 255), 'author' => '山田太郎']);
        $this->一覧IDを確認($this->get($this->URL(['keyword' => str_repeat('あ', 255)]))->assertOk(), [$book->id]);
        $zero = Book::factory()->create(['title' => '0から学ぶPHP', 'author' => '鈴木花子']);
        $response = $this->get($this->URL(['keyword' => '0']))->assertOk();
        $this->一覧IDを確認($response, [$zero->id]);
        // 提供Bladeの真偽判定による件数表示の制約。検索処理はゼロも条件にする。
        $response->assertDontSeeText('検索結果:');
        $this->assertSame('0', $this->XPath($response)->query('//input[@name="keyword"]')->item(0)->getAttribute('value'));
    }

    #[DataProvider('不正な検索条件')]
    public function test_不正条件を描画せず解除して通知し再リダイレクトしない(array $filters, string $field, string $message): void
    {
        $book = Book::factory()->create();
        $before = $this->DB状態();
        $response = $this->from($this->URL($filters))->get($this->URL($filters));
        $response->assertRedirect(route('books.index'))->assertSessionHasErrors($field)
            ->assertSessionHas('error', $message.' 検索条件を解除し、全書籍を表示しています。')
            ->assertSessionMissing('_old_input');

        $page = $this->get($response->headers->get('Location'))->assertOk();
        $page->assertSeeText($message)->assertSeeText('検索条件を解除し、全書籍を表示しています。')->assertDontSeeText('検索結果:');
        $this->一覧IDを確認($page, [$book->id]);
        $this->assertSame('', $this->XPath($page)->query('//input[@name="keyword"]')->item(0)->getAttribute('value'));
        $this->get(route('books.index'))->assertOk()->assertDontSeeText('検索条件を解除し、全書籍を表示しています。');
        $this->assertSame($before, $this->DB状態());
    }

    public static function 不正な検索条件(): array
    {
        return [
            'キーワード配列' => [['keyword' => ['invalid']], 'keyword', 'キーワードは文字列で入力してください。'],
            'キーワード入れ子' => [['keyword' => [['invalid']]], 'keyword', 'キーワードは文字列で入力してください。'],
            '256文字' => [['keyword' => str_repeat('あ', 256)], 'keyword', 'キーワードは255文字以内で入力してください。'],
            'ジャンル配列' => [['genre' => ['1']], 'genre', 'ジャンルの選択値が正しくありません。'],
            'ジャンル入れ子' => [['genre' => [['1']]], 'genre', 'ジャンルの選択値が正しくありません。'],
            'ジャンル文字列' => [['genre' => 'abc'], 'genre', 'ジャンルの選択値が正しくありません。'],
            'ジャンル小数' => [['genre' => '1.5'], 'genre', 'ジャンルの選択値が正しくありません。'],
            'ジャンルゼロ' => [['genre' => '0'], 'genre', 'ジャンルの選択値が正しくありません。'],
            '不存在ジャンル' => [['keyword' => 'PHP', 'genre' => '2147483647'], 'genre', '選択したジャンルは存在しません。'],
            '巨大ジャンルID' => [['genre' => str_repeat('9', 50)], 'genre', 'ジャンルの選択値が正しくありません。'],
        ];
    }

    public function test_htm_lを含む検索条件を安全に表示する(): void
    {
        $keyword = '\"><script>alert(1)</script>';
        $response = $this->get($this->URL(['keyword' => $keyword]))->assertOk();
        $xpath = $this->XPath($response);
        $this->assertSame($keyword, $xpath->query('//input[@name="keyword"]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//script[contains(text(), "alert(1)")]')->length);
    }

    public function test_検索後の10件区切りと安定順序とページリンクに条件を保持する(): void
    {
        $genre = Genre::factory()->create();
        $books = Book::factory()->count(11)->create(['title' => 'PHP入門', 'created_at' => '2026-11-01 12:00:00']);
        foreach ($books as $book) {
            $book->genres()->sync([$genre->id]);
        }
        Book::factory()->create(['title' => '対象外', 'author' => '対象外']);
        $filters = ['keyword' => 'PHP', 'genre' => $genre->id];
        $expectedIds = $books->pluck('id')->sortDesc()->values()->all();

        $response = $this->get($this->URL($filters + ['ignored' => ['unsafe']]))->assertOk();
        $this->一覧IDを確認($response, array_slice($expectedIds, 0, 10));
        $paginator = $response->viewData('books');
        $this->assertSame(11, $paginator->total());
        $this->assertSame(10, $paginator->perPage());
        parse_str(parse_url($paginator->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame(['keyword' => 'PHP', 'genre' => (string) $genre->id, 'page' => '2'], $query);
        $this->一覧IDを確認($this->get($paginator->nextPageUrl())->assertOk(), array_slice($expectedIds, 10));
    }

    #[DataProvider('不正なページ')]
    public function test_不正ページの補正先に検索条件を保持する(mixed $page): void
    {
        $filters = ['keyword' => 'PHP'];
        $this->get($this->URL($filters + ['page' => $page]))->assertRedirect($this->URL($filters));
    }

    public static function 不正なページ(): array
    {
        return ['ゼロ' => ['0'], '負数' => ['-1'], '文字列' => ['abc'], '小数' => ['1.5'], '配列' => [['1']]];
    }

    public function test_検索結果の最終ページへ補正し巨大_offse_tを発行しない(): void
    {
        Book::factory()->count(11)->create(['title' => 'PHP入門']);
        $filters = ['keyword' => 'PHP'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get($this->URL($filters + ['page' => '2147483647']))->assertRedirect($this->URL($filters + ['page' => 2]));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\boffset\b/i', $query['query']);
        }
        $missing = ['keyword' => '該当なし'];
        $this->get($this->URL($missing + ['page' => 2]))->assertRedirect($this->URL($missing));
        $this->get($this->URL($missing))->assertOk()->assertSeeText('検索結果: 0件');
    }

    public function test_ap_i用ジャンルパラメータは_webの絞り込みに適用しない(): void
    {
        $book = Book::factory()->create();
        $this->一覧IDを確認($this->get($this->URL(['genre_id' => 2147483647]))->assertOk(), [$book->id]);
    }

    private function DB状態(): array
    {
        $snapshot = [];

        foreach (['users', 'books', 'genres', 'book_genres', 'reviews', 'favorites', 'review_likes'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function URL(array $filters): string
    {
        return route('books.index', $filters);
    }

    private function 一覧IDを確認(TestResponse $response, array $expectedIds): void
    {
        $this->assertSame($expectedIds, $response->viewData('books')->pluck('id')->all());
    }

    private function XPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }
}
