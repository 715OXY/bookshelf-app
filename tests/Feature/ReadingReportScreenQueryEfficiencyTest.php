<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingReportScreenQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());
        $result = $connection->selectOne('SELECT DATABASE() AS database_name');
        $this->assertSame('testing', $result->database_name);
    }

    // Q02
    public function test_描画件数と元データが増えてもレポート全体のクエリ数が増えない(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $ids = $books = $genres = [];
        $previous = null;

        foreach ([1 => 1, 5 => 4, 10 => 5] as $bookCount => $additional) {
            [$newIds, $newBooks, $newGenres] = $this->createReviews($user, count($books) + 1, $additional);
            $ids = array_merge($ids, $newIds);
            $books = array_merge($books, $newBooks);
            $genres = array_merge($genres, $newGenres);
            $this->assertCount($bookCount, $books);
            $this->assertCount($bookCount * 2, $ids);
            $this->assertCount($bookCount * 2, $genres);

            // 各段階で同じ準備GETを計測外に行い、初回処理の条件を揃える。
            $this->get('/reports')->assertOk();
            [[$response, $html], $selects, $queries] = $this->measure($user, function (): array {
                $response = $this->get('/reports');

                return [$response, $response->getContent()];
            });

            $response->assertOk()->assertViewIs('reports.index');
            $this->assertAuthenticatedAs($user, 'web');
            $this->assertGreaterThan(0, $selects);
            $this->assertStats($response->viewData('stats'), $books, $genres);
            $this->assertIsString($html);
            $this->assertRendered($html, $books, $genres);
            if ($previous !== null) {
                $this->assertSame($previous, [$selects, $queries], 'HTTP全体のSELECT・全SQL件数が増えています。');
            }
            $previous = [$selects, $queries];
        }
    }

    /**
     * 計測外で評価1・5と、各書籍に異なる2ジャンルを追加する。
     *
     * @return array{list<int>, list<Book>, list<Genre>}
     */
    private function createReviews(User $user, int $first, int $count): array
    {
        $ids = [];
        $books = [];
        $genres = [];
        foreach (range($first, $first + $count - 1) as $number) {
            $book = Book::factory()->for($user)->create([
                'title' => '計測書籍'.$number, 'author' => '計測著者'.$number,
                'isbn' => sprintf('978000000%04d', $number), 'published_date' => '2026-11-01',
            ]);
            $firstGenre = Genre::factory()->create(['name' => '計測ジャンル'.$number.'A']);
            $secondGenre = Genre::factory()->create(['name' => '計測ジャンル'.$number.'B']);
            $book->genres()->sync([$firstGenre->id, $secondGenre->id]);
            $books[] = $book;
            array_push($genres, $firstGenre, $secondGenre);
            foreach ([1, 5] as $rating) {
                $ids[] = Review::factory()->for($user)->for($book)->create(['rating' => $rating])->id;
            }
        }

        return [$ids, $books, $genres];
    }

    /**
     * Fixtureから独立に指定した評価1・5の期待値と、完全な4統計契約を照合する。
     *
     * @param  array<string, mixed>  $stats
     * @param  list<Book>  $books
     * @param  list<Genre>  $genres
     */
    private function assertStats(array $stats, array $books, array $genres): void
    {
        $count = count($books);
        $this->assertSame(['summary', 'rating_distribution', 'top_rated_books', 'genre_ratings'], array_keys($stats));
        $this->assertSame(['total_reviews' => $count * 2, 'books_read' => $count, 'average_rating' => 3.0], $stats['summary']);
        $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        $this->assertSame([$count, 0, 0, 0, $count], $stats['rating_distribution']->all());
        $this->assertSame($count * 2, $stats['rating_distribution']->sum());
        $this->assertTrue(array_is_list($stats['top_rated_books']));
        $this->assertTrue(array_is_list($stats['genre_ratings']));
        // Fixtureは数値ID昇順に作成。全候補が同評価・同件数なので先頭5件が期待順位。
        $this->assertSame(array_map(fn (Book $book): array => [
            'id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'rating' => 5,
        ], array_slice($books, 0, 5)), $stats['top_rated_books']);
        $this->assertSame(array_map(fn (Genre $genre): array => [
            'id' => $genre->id, 'name' => $genre->name, 'count' => 2, 'average_rating' => 3.0,
        ], array_slice($genres, 0, 5)), $stats['genre_ratings']);
    }

    /**
     * TOP5の各領域で件数・順位・表示情報・リンクを照合する。
     *
     * @param  list<Book>  $books
     * @param  list<Genre>  $genres
     */
    private function assertRendered(string $html, array $books, array $genres): void
    {
        $document = new DOMDocument;
        $this->assertTrue(@$document->loadHTML('<?xml encoding="UTF-8">'.$html));
        $xpath = new DOMXPath($document);

        foreach ([
            ['高評価書籍 TOP5', 'books.show', $books],
            ['ジャンル別評価傾向 TOP5', 'genres.show', $genres],
        ] as [$heading, $route, $models]) {
            $path = '//main//h3[normalize-space()="'.$heading.'"]/parent::div//a';
            $links = $xpath->query($path);
            $this->assertNotFalse($links);
            $expected = array_slice($models, 0, 5);
            $this->assertSame(count($expected), $links->length);
            foreach ($expected as $index => $model) {
                $linkPath = '('.$path.')['.($index + 1).']';
                $this->assertSame(route($route, $model), $xpath->evaluate('string('.$linkPath.'/@href)'));
                $text = preg_replace('/\s+/u', ' ', trim($links->item($index)->textContent));
                $content = $route === 'books.show'
                    ? $model->title.' '.$model->author.' ★★★★★'
                    : $model->name.' 2件のレビュー 3.0 平均評価';
                $this->assertSame(($index + 1).' '.$content, $text);
            }
        }
    }

    /**
     * 対象接続の処理だけを計測し、例外時も元のDispatcherへ戻す。
     * Dispatcher不在時は設置前に失敗させ、計測不能を0件成功にしない。
     *
     * @param  callable(): mixed  $operation
     * @return array{mixed, int, int}
     */
    private function measure(User $user, callable $operation): array
    {
        $connection = $user->getConnection();
        $this->assertSame(DB::connection(), $connection);
        $this->assertSame($connection, $user->reviews()->getQuery()->getConnection());
        foreach ([new Review, new Book, new Genre] as $model) {
            $this->assertSame($connection, $model->getConnection());
        }
        $originalDispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($originalDispatcher);
        $dispatcher = clone $originalDispatcher;
        $selects = 0;
        $queries = 0;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($connection, &$selects, &$queries): void {
            if ($event->connection !== $connection) {
                return;
            }
            $queries++;
            if (preg_match('/^\s*select\b/i', $event->sql) === 1) {
                $selects++;
            }
        });

        try {
            $connection->setEventDispatcher($dispatcher);
            $result = $operation();

            return [$result, $selects, $queries];
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
    }
}
