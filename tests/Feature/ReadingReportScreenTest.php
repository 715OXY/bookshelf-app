<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReadingReportScreenTest extends TestCase
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

    // H02：集計値はユーザー承認済み暫定仕様の独立した期待値。
    public function test_本人の四統計を完全な契約と表示内容で返す(): void
    {
        [$user, $books, $genres] = $this->makeReport();
        $response = $this->actingAs($user, 'web')->get('/reports')->assertOk()->assertViewIs('reports.index');
        $stats = $response->viewData('stats');

        $this->assertContract($stats);
        $this->assertSame(['total_reviews' => 5, 'books_read' => 4, 'average_rating' => 3.6], $stats['summary']);
        $this->assertSame([1, 0, 1, 1, 2], $stats['rating_distribution']->all());
        $this->assertLessThan($books[2]->id, $books[0]->id);
        $this->assertSame([
            ['id' => $books[0]->id, 'title' => '書籍A', 'author' => '著者A', 'rating' => 5],
            ['id' => $books[2]->id, 'title' => '書籍C', 'author' => '著者C', 'rating' => 5],
            ['id' => $books[1]->id, 'title' => '書籍B', 'author' => '著者B', 'rating' => 4],
        ], $stats['top_rated_books']);
        $this->assertSame([$genres[0]->id, $genres[1]->id], array_column($stats['genre_ratings'], 'id'));
        $this->assertSame(['小説', 'ミステリー'], array_column($stats['genre_ratings'], 'name'));
        $this->assertSame([3, 3], array_column($stats['genre_ratings'], 'count'));
        $this->assertEqualsWithDelta(10 / 3, $stats['genre_ratings'][0]['average_rating'], 1.0E-12);
        $this->assertSame(3.0, $stats['genre_ratings'][1]['average_rating']);

        $xpath = $this->xpath($response);
        $this->assertSame('マイ読書レポート', $this->textAt($xpath, '//header//h2'));
        foreach (['総レビュー数' => '5', '読了冊数' => '4', '平均評価' => '3.6'] as $label => $value) {
            $this->assertSame($value, $this->textAt($xpath, '//main//h3[normalize-space()="基本統計"]/following-sibling::div/div/div[normalize-space()="'.$label.'"]/preceding-sibling::div'));
        }
        $rows = '//main//h3[normalize-space()="評価分布"]/following-sibling::div/div';
        $this->assertSame(5, $xpath->query($rows)->length);
        foreach (['★', '★★', '★★★', '★★★★', '★★★★★'] as $index => $stars) {
            $row = '('.$rows.')['.($index + 1).']';
            $this->assertSame($stars, $this->textAt($xpath, $row.'/div[1]/span'));
            $this->assertSame([1, 0, 1, 1, 2][$index].'件', $this->textAt($xpath, $row.'/div[3]'));
            $this->assertSame('width: '.[50, 0, 50, 50, 100][$index].'%', $xpath->evaluate('string('.$row.'/div[2]/div/div/@style)'));
        }
    }

    // H05
    public function test_レビュー零件でも完全な空状態と平均未定義表示を返す(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'web')->get('/reports')->assertOk()->assertViewIs('reports.index');
        $stats = $response->viewData('stats');
        $this->assertContract($stats);
        $this->assertSame(['total_reviews' => 0, 'books_read' => 0, 'average_rating' => 0.0], $stats['summary']);
        $this->assertSame([0, 0, 0, 0, 0], $stats['rating_distribution']->all());
        $this->assertSame([], $stats['top_rated_books']);
        $this->assertSame([], $stats['genre_ratings']);

        $xpath = $this->xpath($response);
        foreach (['総レビュー数' => '0', '読了冊数' => '0', '平均評価' => '-'] as $label => $value) {
            $this->assertSame($value, $this->textAt($xpath, '//main//h3[normalize-space()="基本統計"]/following-sibling::div/div/div[normalize-space()="'.$label.'"]/preceding-sibling::div'));
        }
        $this->assertSame('4星以上の書籍がありません', $this->textAt($xpath, '//main//h3[normalize-space()="高評価書籍 TOP5"]/following-sibling::p'));
        $this->assertSame('ジャンルが設定された書籍のレビューがありません', $this->textAt($xpath, '//main//h3[normalize-space()="ジャンル別評価傾向 TOP5"]/parent::div/p[last()]'));
        $this->assertSame(5, $xpath->query('//main//h3[normalize-space()="評価分布"]/following-sibling::div//div[@style="width: 0%"]')->length);
    }

    // H06
    public function test_五件未満の実データだけを順位と星と詳細リンク付きで描画する(): void
    {
        [$user, $books, $genres] = $this->makeReport();
        $response = $this->actingAs($user, 'web')->get('/reports')->assertOk();
        $stats = $response->viewData('stats');
        $this->assertCount(3, $stats['top_rated_books']);
        $this->assertCount(2, $stats['genre_ratings']);
        $xpath = $this->xpath($response);
        $bookLinks = '//main//h3[normalize-space()="高評価書籍 TOP5"]/parent::div//a';
        $genreLinks = '//main//h3[normalize-space()="ジャンル別評価傾向 TOP5"]/parent::div//a';
        $this->assertSame(3, $xpath->query($bookLinks)->length);
        $this->assertSame(2, $xpath->query($genreLinks)->length);
        foreach ([[0, '1 書籍A 著者A ★★★★★'], [2, '2 書籍C 著者C ★★★★★'], [1, '3 書籍B 著者B ★★★★☆']] as $index => [$bookIndex, $text]) {
            $path = '('.$bookLinks.')['.($index + 1).']';
            $this->assertSame($text, $this->textAt($xpath, $path));
            $this->assertSame(route('books.show', $books[$bookIndex]), $xpath->evaluate('string('.$path.'/@href)'));
        }
        foreach (['1 小説 3件のレビュー 3.3 平均評価', '2 ミステリー 3件のレビュー 3.0 平均評価'] as $index => $text) {
            $path = '('.$genreLinks.')['.($index + 1).']';
            $this->assertSame($text, $this->textAt($xpath, $path));
            $this->assertSame(route('genres.show', $genres[$index]), $xpath->evaluate('string('.$path.'/@href)'));
        }
    }

    // H07
    public function test_レポートから他会員登録書籍と対象ジャンルの詳細へ遷移できる(): void
    {
        [$user, $books, $genres] = $this->makeReport();
        $this->assertNotSame($user->id, $books[0]->user_id);
        $response = $this->actingAs($user, 'web')->get('/reports')->assertOk();
        $xpath = $this->xpath($response);
        $bookUrl = $xpath->evaluate('string((//main//h3[normalize-space()="高評価書籍 TOP5"]/parent::div//a)[1]/@href)');
        $genreUrl = $xpath->evaluate('string((//main//h3[normalize-space()="ジャンル別評価傾向 TOP5"]/parent::div//a)[1]/@href)');
        $this->assertSame(route('books.show', $books[0]), $bookUrl);
        $this->assertSame(route('genres.show', $genres[0]), $genreUrl);
        $this->get($bookUrl)->assertOk()->assertViewIs('books.show')->assertViewHas('book', fn (Book $book): bool => $book->is($books[0]));
        $this->get($genreUrl)->assertOk()->assertViewIs('genres.show')->assertViewHas('genre', fn (Genre $genre): bool => $genre->is($genres[0]));
    }

    // H08：TD-86-01、書籍側だけが存在するHTTP空状態を補完する。
    public function test_高評価書籍だけが存在してもジャンルの空状態を表示する(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create(['title' => '片側空の高評価書籍', 'author' => '高評価側著者']);
        $book->genres()->detach();
        Review::factory()->for($user)->for($book)->create(['rating' => 5]);

        $response = $this->actingAs($user, 'web')->get('/reports')->assertOk()->assertViewIs('reports.index');
        $stats = $response->viewData('stats');
        $this->assertContract($stats);
        $this->assertSame(['total_reviews' => 1, 'books_read' => 1, 'average_rating' => 5.0], $stats['summary']);
        $this->assertSame([0, 0, 0, 0, 1], $stats['rating_distribution']->all());
        $this->assertSame([
            ['id' => $book->id, 'title' => '片側空の高評価書籍', 'author' => '高評価側著者', 'rating' => 5],
        ], $stats['top_rated_books']);
        $this->assertSame([], $stats['genre_ratings']);

        $xpath = $this->xpath($response);
        foreach (['総レビュー数' => '1', '読了冊数' => '1', '平均評価' => '5.0'] as $label => $value) {
            $this->assertSame($value, $this->textAt($xpath, '//main//h3[normalize-space()="基本統計"]/following-sibling::div/div/div[normalize-space()="'.$label.'"]/preceding-sibling::div'));
        }
        $rows = '//main//h3[normalize-space()="評価分布"]/following-sibling::div/div';
        $this->assertSame(5, $xpath->query($rows)->length);
        foreach (['★', '★★', '★★★', '★★★★', '★★★★★'] as $index => $stars) {
            $row = '('.$rows.')['.($index + 1).']';
            $this->assertSame($stars, $this->textAt($xpath, $row.'/div[1]/span'));
            $this->assertSame([0, 0, 0, 0, 1][$index].'件', $this->textAt($xpath, $row.'/div[3]'));
        }
        $bookArea = '//main//h3[normalize-space()="高評価書籍 TOP5"]/parent::div';
        $genreArea = '//main//h3[normalize-space()="ジャンル別評価傾向 TOP5"]/parent::div';
        $this->assertSame(1, $xpath->query($bookArea.'//a')->length);
        $this->assertSame('1 片側空の高評価書籍 高評価側著者 ★★★★★', $this->textAt($xpath, $bookArea.'//a'));
        $this->assertSame(route('books.show', $book), $xpath->evaluate('string('.$bookArea.'//a/@href)'));
        $this->assertSame(0, $xpath->query($bookArea.'/p[normalize-space()="4星以上の書籍がありません"]')->length);
        $this->assertSame('ジャンルが設定された書籍のレビューがありません', $this->textAt($xpath, $genreArea.'/p[last()]'));
        $this->assertSame(0, $xpath->query($genreArea.'//a')->length);
        $this->assertSame(0, $xpath->query($genreArea.'/div')->length);
    }

    // H09：TD-86-01、ジャンル側だけが存在するHTTP空状態を補完する。
    public function test_ジャンル評価だけが存在しても高評価書籍の空状態を表示する(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create(['title' => '片側空の低評価書籍', 'author' => '低評価側著者']);
        $genre = Genre::factory()->create(['name' => '片側空の対象ジャンル']);
        $book->genres()->sync([$genre->id]);
        Review::factory()->for($user)->for($book)->create(['rating' => 3]);

        $response = $this->actingAs($user, 'web')->get('/reports')->assertOk()->assertViewIs('reports.index');
        $stats = $response->viewData('stats');
        $this->assertContract($stats);
        $this->assertSame(['total_reviews' => 1, 'books_read' => 1, 'average_rating' => 3.0], $stats['summary']);
        $this->assertSame([0, 0, 1, 0, 0], $stats['rating_distribution']->all());
        $this->assertSame([], $stats['top_rated_books']);
        $this->assertSame([
            ['id' => $genre->id, 'name' => '片側空の対象ジャンル', 'count' => 1, 'average_rating' => 3.0],
        ], $stats['genre_ratings']);

        $xpath = $this->xpath($response);
        foreach (['総レビュー数' => '1', '読了冊数' => '1', '平均評価' => '3.0'] as $label => $value) {
            $this->assertSame($value, $this->textAt($xpath, '//main//h3[normalize-space()="基本統計"]/following-sibling::div/div/div[normalize-space()="'.$label.'"]/preceding-sibling::div'));
        }
        $rows = '//main//h3[normalize-space()="評価分布"]/following-sibling::div/div';
        $this->assertSame(5, $xpath->query($rows)->length);
        foreach (['★', '★★', '★★★', '★★★★', '★★★★★'] as $index => $stars) {
            $row = '('.$rows.')['.($index + 1).']';
            $this->assertSame($stars, $this->textAt($xpath, $row.'/div[1]/span'));
            $this->assertSame([0, 0, 1, 0, 0][$index].'件', $this->textAt($xpath, $row.'/div[3]'));
        }
        $bookArea = '//main//h3[normalize-space()="高評価書籍 TOP5"]/parent::div';
        $genreArea = '//main//h3[normalize-space()="ジャンル別評価傾向 TOP5"]/parent::div';
        $this->assertSame('4星以上の書籍がありません', $this->textAt($xpath, $bookArea.'/p'));
        $this->assertSame(0, $xpath->query($bookArea.'//a')->length);
        $this->assertSame(0, $xpath->query($bookArea.'/div')->length);
        $this->assertSame(1, $xpath->query($genreArea.'//a')->length);
        $this->assertSame('1 片側空の対象ジャンル 1件のレビュー 3.0 平均評価', $this->textAt($xpath, $genreArea.'//a'));
        $this->assertSame(route('genres.show', $genre), $xpath->evaluate('string('.$genreArea.'//a/@href)'));
        $this->assertSame(0, $xpath->query($genreArea.'/p[normalize-space()="ジャンルが設定された書籍のレビューがありません"]')->length);
    }

    /**
     * @return array{User, list<Book>, list<Genre>}
     */
    private function makeReport(): array
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $genres = [Genre::factory()->create(['name' => '小説']), Genre::factory()->create(['name' => 'ミステリー'])];
        $books = [];
        foreach (['A', 'B', 'C', 'D'] as $index => $name) {
            $books[] = Book::factory()->for($index % 2 === 0 ? $other : $user)->create(['title' => '書籍'.$name, 'author' => '著者'.$name]);
        }
        foreach ([[0, 1], [0], [], [1]] as $index => $memberships) {
            $books[$index]->genres()->sync(array_map(fn (int $id): int => $genres[$id]->id, $memberships));
        }
        foreach ([[5, 1], [4], [5], [3]] as $index => $ratings) {
            foreach ($ratings as $rating) {
                Review::factory()->for($user)->for($books[$index])->create(['rating' => $rating]);
            }
        }
        Review::factory()->for($other)->for($books[1])->create(['rating' => 2]);

        return [$user, $books, $genres];
    }

    /** @param array<string, mixed> $stats */
    private function assertContract(array $stats): void
    {
        $this->assertSame(['summary', 'rating_distribution', 'top_rated_books', 'genre_ratings'], array_keys($stats));
        $this->assertSame(['total_reviews', 'books_read', 'average_rating'], array_keys($stats['summary']));
        $this->assertIsInt($stats['summary']['total_reviews']);
        $this->assertIsInt($stats['summary']['books_read']);
        $this->assertIsFloat($stats['summary']['average_rating']);
        $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        foreach ($stats['rating_distribution'] as $count) {
            $this->assertIsInt($count);
        }
        $this->assertSame($stats['summary']['total_reviews'], $stats['rating_distribution']->sum());
        foreach (['top_rated_books', 'genre_ratings'] as $key) {
            $this->assertTrue(array_is_list($stats[$key]));
        }
        foreach ($stats['top_rated_books'] as $book) {
            $this->assertSame(['id', 'title', 'author', 'rating'], array_keys($book));
            $this->assertIsInt($book['id']);
            $this->assertIsString($book['title']);
            $this->assertIsString($book['author']);
            $this->assertIsInt($book['rating']);
        }
        foreach ($stats['genre_ratings'] as $genre) {
            $this->assertSame(['id', 'name', 'count', 'average_rating'], array_keys($genre));
            $this->assertIsInt($genre['id']);
            $this->assertIsString($genre['name']);
            $this->assertIsInt($genre['count']);
            $this->assertIsFloat($genre['average_rating']);
        }
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new DOMXPath($document);
    }

    private function textAt(DOMXPath $xpath, string $path): string
    {
        $nodes = $xpath->query($path);
        $this->assertSame(1, $nodes->length);

        return preg_replace('/\s+/u', ' ', trim($nodes->item(0)->textContent));
    }
}
