<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookIndexSortTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('主ソートの条件')]
    public function test_主ソートを優先し入力表記と選択属性を維持する(array $filters, array $expectedOrder): void
    {
        $reviewer = User::factory()->create();
        $books = [];

        foreach ([
            ['Bravo', '2026-11-02 12:00:00', 2],
            ['Alpha', '2026-11-03 12:00:00', 3],
            ['Charlie', '2026-11-01 12:00:00', 5],
            ['Delta', '2026-11-04 12:00:00', null],
        ] as [$title, $createdAt, $rating]) {
            $book = Book::factory()->create(['title' => $title, 'created_at' => $createdAt]);
            $books[] = $book;

            if ($rating !== null) {
                Review::factory()->create(['book_id' => $book->id, 'user_id' => $reviewer->id, 'rating' => $rating]);
            }
        }

        $response = $this->get(route('books.index', $filters))->assertOk()->assertSessionMissing('error');
        $this->assertSame(
            array_map(fn (int $index): int => $books[$index]->id, $expectedOrder),
            $response->viewData('books')->pluck('id')->all()
        );

        parse_str(parse_url($response->viewData('books')->url(2), PHP_URL_QUERY), $query);
        $sort = $filters['sort'] ?? null;

        if ($sort === null || trim($sort) === '') {
            $this->assertArrayNotHasKey('sort', $query);
        } else {
            $this->assertSame($sort, $query['sort']);
        }

        $xpath = $this->XPath($response);
        $selected = $xpath->query('//select[@name="sort"]/option[@selected]');
        $this->assertSame('newest', $xpath->query('//select[@name="sort"]/option')->item(0)->getAttribute('value'));

        if ($sort === null || trim($sort) === '' || $sort === 'latest') {
            // 実ブラウザーの先頭option選択はユーザー確認。属性の偽装はしない。
            $this->assertSame(0, $selected->length);
        } else {
            $this->assertSame(1, $selected->length);
            $this->assertSame($sort, $selected->item(0)->getAttribute('value'));
        }
    }

    public static function 主ソートの条件(): array
    {
        return [
            '未指定' => [[], [3, 1, 0, 2]],
            '空欄' => [['sort' => ''], [3, 1, 0, 2]],
            '半角空白' => [['sort' => '   '], [3, 1, 0, 2]],
            '正式な最新順' => [['sort' => 'latest'], [3, 1, 0, 2]],
            '互換入力' => [['sort' => 'newest'], [3, 1, 0, 2]],
            '古い順' => [['sort' => 'oldest'], [2, 0, 1, 3]],
            'タイトル順' => [['sort' => 'title'], [1, 0, 2, 3]],
            '評価順と未評価' => [['sort' => 'rating'], [2, 1, 0, 3]],
        ];
    }

    #[DataProvider('副ソートの条件')]
    public function test_同タイトル同平均同日時の順序も確定する(string $sort, array $expectedOrder): void
    {
        $reviewer = User::factory()->create();
        $books = [];

        foreach (['2026-11-02', '2026-11-01', '2026-11-02', '2026-11-01'] as $date) {
            $book = Book::factory()->create(['title' => 'Same title', 'created_at' => $date.' 12:00:00']);
            Review::factory()->create(['book_id' => $book->id, 'user_id' => $reviewer->id, 'rating' => 4]);
            $books[] = $book;
        }

        $response = $this->get(route('books.index', ['sort' => $sort]))->assertOk();

        $this->assertSame(
            array_map(fn (int $index): int => $books[$index]->id, $expectedOrder),
            $response->viewData('books')->pluck('id')->all()
        );
    }

    public static function 副ソートの条件(): array
    {
        return [
            '最新順' => ['latest', [2, 0, 3, 1]],
            '互換最新順' => ['newest', [2, 0, 3, 1]],
            '古い順' => ['oldest', [1, 3, 0, 2]],
            '同タイトル' => ['title', [2, 0, 3, 1]],
            '同平均' => ['rating', [2, 0, 3, 1]],
        ];
    }

    public function test_丸め前の全レビュー平均を使い未評価を順序付きで最後に表示する(): void
    {
        $reviewer = User::factory()->create();
        $lower = Book::factory()->create(['created_at' => '2026-11-20 12:00:00']);
        $higher = Book::factory()->create(['created_at' => '2026-10-01 12:00:00']);
        $unratedOld = Book::factory()->create(['created_at' => '2026-12-01 12:00:00']);
        $unratedNew = Book::factory()->create(['created_at' => '2026-12-03 12:00:00']);
        $unratedTie = Book::factory()->create(['created_at' => '2026-12-03 12:00:00']);

        foreach ([[$lower, [4, 4, 4, 5]], [$higher, [4, 4, 4, 4, 5, 5, 4]]] as [$book, $ratings]) {
            foreach ($ratings as $rating) {
                Review::factory()->create(['book_id' => $book->id, 'user_id' => $reviewer->id, 'rating' => $rating]);
            }
        }

        $response = $this->get(route('books.index', ['sort' => 'rating']))->assertOk();
        $books = $response->viewData('books');

        $this->assertSame(
            [$higher->id, $lower->id, $unratedTie->id, $unratedNew->id, $unratedOld->id],
            $books->pluck('id')->all()
        );
        $lowerAverage = (float) $books->firstWhere('id', $lower->id)->reviews_avg_rating;
        $higherAverage = (float) $books->firstWhere('id', $higher->id)->reviews_avg_rating;
        $this->assertEqualsWithDelta(4.25, $lowerAverage, 0.0001);
        $this->assertEqualsWithDelta(30 / 7, $higherAverage, 0.0001);
        $this->assertGreaterThan($lowerAverage, $higherAverage);
        $this->assertSame(number_format($lowerAverage, 1), number_format($higherAverage, 1));
        $this->assertNull($books->firstWhere('id', $unratedOld->id)->reviews_avg_rating);
        $this->assertSame(5, $books->total());
    }

    public function test_同平均ではレビュー件数より承認済みの副ソートを優先する(): void
    {
        $reviewer = User::factory()->create();
        $newer = Book::factory()->create(['created_at' => '2026-11-20 12:00:00']);
        $older = Book::factory()->create(['created_at' => '2026-11-01 12:00:00']);

        Review::factory()->create(['book_id' => $newer->id, 'user_id' => $reviewer->id, 'rating' => 4]);

        foreach ([5, 4, 3] as $rating) {
            Review::factory()->create(['book_id' => $older->id, 'user_id' => $reviewer->id, 'rating' => $rating]);
        }

        $response = $this->get(route('books.index', ['sort' => 'rating']))->assertOk();
        $this->assertSame([$newer->id, $older->id], $response->viewData('books')->pluck('id')->all());
    }

    #[DataProvider('不正な並び順')]
    public function test_不正な並び順では全条件を解除し通知が終了してもデータと認証を保つ(bool $authenticated, mixed $sort): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create(['title' => '検索対象']);
        $genre = $book->genres()->first();

        if ($authenticated) {
            $this->actingAs($user, 'web');
        }

        $before = $this->DB状態();
        $url = route('books.index', ['keyword' => '検索対象', 'genre' => $genre->id, 'sort' => $sort, 'page' => 2]);
        $message = '並び順の指定が正しくありません。 検索条件を解除し、全書籍を表示しています。';
        $response = $this->from($url)->get($url);
        $response->assertRedirect(route('books.index'))->assertSessionHasErrors('sort')
            ->assertSessionHas('error', $message)->assertSessionMissing('_old_input');

        $page = $this->get($response->headers->get('Location'))->assertOk()->assertSeeText($message);
        $this->assertSame([$book->id], $page->viewData('books')->pluck('id')->all());
        $xpath = $this->XPath($page);
        $this->assertSame('', $xpath->query('//input[@name="keyword"]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//select[@name="genre"]/option[@selected]')->length);
        $this->assertSame(0, $xpath->query('//select[@name="sort"]/option[@selected]')->length);
        $this->get(route('books.index'))->assertOk()->assertDontSeeText($message);
        $this->assertSame($before, $this->DB状態());

        if ($authenticated) {
            $this->assertAuthenticatedAs($user, 'web');
        } else {
            $this->assertGuest('web');
        }
    }

    public static function 不正な並び順(): array
    {
        $cases = [];

        foreach ([false, true] as $authenticated) {
            foreach ([
                '未知値' => 'unknown',
                '大文字' => 'LATEST',
                'ゼロ' => '0',
                '列名と方向' => 'title desc',
                '配列' => ['latest'],
                '入れ子配列' => [['latest']],
                '連想配列' => ['column' => 'title'],
            ] as $name => $sort) {
                $cases[($authenticated ? '会員・' : 'ゲスト・').$name] = [$authenticated, $sort];
            }
        }

        return $cases;
    }

    #[DataProvider('不正な並び順の型')]
    public function test_不正な並び順の要求は既存の形式で検証エラーを返す(mixed $sort): void
    {
        Book::factory()->create();
        $before = $this->DB状態();

        $this->getJson(route('books.index', ['sort' => $sort]))
            ->assertUnprocessable()->assertJsonValidationErrors('sort')
            ->assertJsonPath('errors.sort.0', '並び順の指定が正しくありません。')
            ->assertSessionMissing('error');

        $this->assertSame($before, $this->DB状態());
    }

    public static function 不正な並び順の型(): array
    {
        return ['文字列' => ['bad'], '配列' => [['latest']], '入れ子配列' => [[['rating']]]];
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

    private function XPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }
}
