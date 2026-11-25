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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookIndexSortPaginationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('閲覧者とソート')]
    public function test_検索ジャンルソートをページ移動と補正先へ保持し重複欠落なく取得する(bool $authenticated, string $sort, array $expectedOrder): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();
        $otherGenre = Genre::factory()->create();
        $books = [];

        for ($i = 0; $i < 12; $i++) {
            $book = Book::factory()->create([
                'title' => sprintf('PHP %02d', 12 - $i),
                'author' => '対象著者',
                'created_at' => sprintf('2026-11-%02d 12:00:00', $i + 1),
            ]);
            $book->genres()->sync([$genre->id, $otherGenre->id]);
            $books[] = $book;

            if ($i < 10) {
                Review::factory()->create([
                    'book_id' => $book->id,
                    'user_id' => $user->id,
                    'rating' => ($i % 5) + 1,
                ]);
            }
        }

        $keywordOnly = Book::factory()->create(['title' => 'PHP 対象外', 'author' => '対象著者']);
        $keywordOnly->genres()->sync([$otherGenre->id]);
        $genreOnly = Book::factory()->create(['title' => '対象外', 'author' => '対象外']);
        $genreOnly->genres()->sync([$genre->id]);

        if ($authenticated) {
            $this->actingAs($user, 'web');
        }

        $before = $this->DB状態();
        $filters = ['keyword' => 'PHP', 'genre' => $genre->id, 'sort' => $sort];
        $response = $this->get(route('books.index', $filters + ['ignored' => ['unsafe']]))
            ->assertOk()->assertSessionMissing('error');
        $first = $response->viewData('books');
        $expectedIds = array_map(fn (int $index): int => $books[$index]->id, $expectedOrder);

        $this->assertSame(12, $first->total());
        $this->assertSame(10, $first->perPage());
        $this->assertSame(1, $first->currentPage());
        $this->assertSame(array_slice($expectedIds, 0, 10), $first->pluck('id')->all());
        $this->assertEquals(
            ['keyword' => 'PHP', 'genre' => (string) $genre->id, 'sort' => $sort, 'page' => '2'],
            $this->URL条件($first->nextPageUrl())
        );

        $secondResponse = $this->get($first->nextPageUrl())->assertOk();
        $second = $secondResponse->viewData('books');
        $this->assertSame(2, $second->currentPage());
        $this->assertSame(12, $second->total());
        $this->assertSame(array_slice($expectedIds, 10), $second->pluck('id')->all());
        $allIds = array_merge($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame($expectedIds, $allIds);
        $this->assertCount(12, array_unique($allIds));

        foreach ([$first, $second] as $page) {
            foreach ($page as $book) {
                $this->assertTrue($book->relationLoaded('genres'));
                $this->assertCount(2, $book->genres);
            }
        }

        $xpath = $this->XPath($secondResponse);
        $this->assertSame('PHP', $xpath->query('//input[@name="keyword"]')->item(0)->getAttribute('value'));
        $this->assertSame((string) $genre->id, $xpath->query('//select[@name="genre"]/option[@selected]')->item(0)->getAttribute('value'));
        $selected = $xpath->query('//select[@name="sort"]/option[@selected]');

        if ($sort === 'latest') {
            $this->assertSame(0, $selected->length);
            $this->assertSame('newest', $xpath->query('//select[@name="sort"]/option')->item(0)->getAttribute('value'));
        } else {
            $this->assertSame(1, $selected->length);
            $this->assertSame($sort, $selected->item(0)->getAttribute('value'));
        }

        $this->get(route('books.index', $filters + ['page' => 999]))
            ->assertRedirect(route('books.index', $filters + ['page' => 2]));
        $this->get(route('books.index', $filters + ['page' => ['1']]))
            ->assertRedirect(route('books.index', $filters));

        // 提供フォームはpageを送信しない。検索語を変更したGETは先頭から取得する。
        $this->assertSame(0, $xpath->query('//form[@method="GET"]//*[@name="page"]')->length);
        $this->assertSame(route('books.index'), $xpath->query('//form[@method="GET"]')->item(0)->getAttribute('action'));
        $changed = $this->get(route('books.index', array_replace($filters, ['keyword' => 'PHP 01'])))
            ->assertOk()->viewData('books');
        $this->assertSame(1, $changed->currentPage());
        $this->assertSame(1, $changed->total());
        $this->assertSame([$books[11]->id], $changed->pluck('id')->all());

        $reset = $this->get(route('books.index'))->assertOk();
        $this->assertSame(14, $reset->viewData('books')->total());
        $this->assertSame(1, $reset->viewData('books')->currentPage());
        $this->assertArrayNotHasKey('sort', $this->URL条件($reset->viewData('books')->nextPageUrl()));
        $this->assertSame($before, $this->DB状態());

        if ($authenticated) {
            $this->assertAuthenticatedAs($user, 'web');
        } else {
            $this->assertGuest('web');
        }
    }

    public static function 閲覧者とソート(): array
    {
        $cases = [];

        foreach ([false, true] as $authenticated) {
            foreach ([
                'latest' => [11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
                'newest' => [11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
                'oldest' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
                'title' => [11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
                'rating' => [9, 4, 8, 3, 7, 2, 6, 1, 5, 0, 11, 10],
            ] as $sort => $order) {
                $cases[($authenticated ? '会員・' : 'ゲスト・').$sort] = [$authenticated, $sort, $order];
            }
        }

        return $cases;
    }

    #[DataProvider('ページ補正とソート')]
    public function test_不正ページの補正先に有効な全条件だけを保持する(string $sort, mixed $page): void
    {
        $book = Book::factory()->create(['title' => 'PHP入門']);
        $genre = $book->genres()->first();
        $filters = ['keyword' => 'PHP', 'genre' => $genre->id, 'sort' => $sort];

        $this->get(route('books.index', $filters + ['page' => $page, 'ignored' => 'unsafe']))
            ->assertRedirect(route('books.index', $filters));
    }

    public static function ページ補正とソート(): array
    {
        $cases = [];

        foreach (['latest', 'newest', 'oldest', 'title', 'rating'] as $sort) {
            foreach (['ゼロ' => '0', '負数' => '-1', '文字列' => 'abc', '小数' => '1.5', '配列' => ['1']] as $name => $page) {
                $cases[$sort.'・'.$name] = [$sort, $page];
            }
        }

        return $cases;
    }

    #[DataProvider('ソートの表記')]
    public function test_巨大ページでも一覧取得前に補正しソート表記を保持する(string $sort): void
    {
        $genre = Genre::factory()->create();
        $books = Book::factory()->count(11)->create(['title' => 'PHP入門']);

        foreach ($books as $book) {
            $book->genres()->sync([$genre->id]);
        }

        $filters = ['keyword' => 'PHP', 'genre' => $genre->id, 'sort' => $sort];
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->get(route('books.index', $filters + ['page' => '2147483647']))
                ->assertRedirect(route('books.index', $filters + ['page' => 2]));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertNotEmpty($queries);

        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\boffset\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/select\s+`?books`?\.\*/i', $query['query']);
        }
    }

    #[DataProvider('ソートの表記')]
    public function test_検索0件と未登録0件をエラーにせず最終ページを先頭へ補正する(string $sort): void
    {
        $empty = $this->get(route('books.index', ['sort' => $sort]))->assertOk()->assertSessionMissing('error');
        $this->assertSame(0, $empty->viewData('books')->total());
        Book::factory()->create(['title' => 'PHP入門', 'author' => '山田太郎']);
        $filters = ['keyword' => '該当なし', 'sort' => $sort];

        $this->get(route('books.index', $filters + ['page' => 999]))->assertRedirect(route('books.index', $filters));
        $response = $this->get(route('books.index', $filters))->assertOk()
            ->assertSeeText('検索結果: 0件')->assertSeeText('書籍が見つかりませんでした。')->assertSessionMissing('error');
        $this->assertSame(0, $response->viewData('books')->total());
        $this->assertSame(1, $response->viewData('books')->currentPage());
    }

    public static function ソートの表記(): array
    {
        return ['正式最新順' => ['latest'], '互換最新順' => ['newest'], '古い順' => ['oldest'], 'タイトル順' => ['title'], '評価順' => ['rating']];
    }

    public function test_検索入力が不正なら有効なソートも解除して既存の通知を維持する(): void
    {
        $this->get(route('books.index', ['keyword' => ['invalid'], 'sort' => 'rating', 'page' => 2]))
            ->assertRedirect(route('books.index'))->assertSessionHasErrors('keyword')
            ->assertSessionHas('error', 'キーワードは文字列で入力してください。 検索条件を解除し、全書籍を表示しています。');
        $this->get(route('books.index'))->assertOk()->assertSeeText('キーワードは文字列で入力してください。');
    }

    private function URL条件(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $query;
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
