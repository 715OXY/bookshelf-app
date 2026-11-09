<?php

namespace Tests\Feature;

use App\Http\Controllers\BookController;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('認証必須の操作')]
    public function test_ゲストの操作はログインへ誘導される(
        string $method,
        string $path
    ): void {
        $book = Book::factory()->create();
        $before = $this->DB状態();

        $this->call($method, sprintf($path, $book->id))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
        $this->assertSame($before, $this->DB状態());
    }

    public static function 認証必須の操作(): array
    {
        return [
            '登録画面' => ['GET', '/books/create'],
            '登録' => ['POST', '/books'],
            '編集画面' => ['GET', '/books/%d/edit'],
            '更新' => ['PUT', '/books/%d'],
            '削除' => ['DELETE', '/books/%d'],
        ];
    }

    #[DataProvider('所有者限定の操作')]
    public function test_他会員の直接操作は403になる(
        string $method,
        string $path
    ): void {
        $book = Book::factory()->create();
        $otherUser = User::factory()->create();
        $before = $this->DB状態();

        $this->actingAs($otherUser, 'web')
            ->call($method, sprintf($path, $book->id))
            ->assertForbidden();

        $this->assertSame($before, $this->DB状態());
    }

    public static function 所有者限定の操作(): array
    {
        return [
            '編集画面' => ['GET', '/books/%d/edit'],
            '更新' => ['PUT', '/books/%d'],
            '削除' => ['DELETE', '/books/%d'],
        ];
    }

    #[DataProvider('不存在の対象への操作')]
    public function test_認証済みでも不存在の書籍は404になる(
        string $method,
        string $path
    ): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->call($method, sprintf($path, 2147483647))
            ->assertNotFound();

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
    }

    public static function 不存在の対象への操作(): array
    {
        return [
            '詳細' => ['GET', '/books/%d'],
            '編集画面' => ['GET', '/books/%d/edit'],
            '更新' => ['PUT', '/books/%d'],
            '削除' => ['DELETE', '/books/%d'],
        ];
    }

    public function test_ゲストも不存在の書籍詳細は404になる(): void
    {
        $this->get('/books/2147483647')
            ->assertNotFound();
    }

    public function test_有効な登録入力と上限値を許可する(): void
    {
        $user = User::factory()->create();
        $genres = Genre::factory()->count(2)->create();

        $payload = [
            'title' => str_repeat('あ', 255),
            'author' => str_repeat('い', 255),
            'isbn' => '9781234567890',
            'published_date' => '2024-02-29',
            'description' => str_repeat('う', 10000),
            'image_url' => 'https://example.com/cover.jpg',
            'genres' => $genres->modelKeys(),
            'user_id' => 2147483647,
        ];

        $before = $this->DB状態();

        $request = $this->検証用Request(
            StoreBookRequest::class,
            'POST',
            '/books',
            $payload,
            $user
        );

        $request->validateResolved();

        $validated = $request->validated();

        $this->assertSame($payload['title'], $validated['title']);
        $this->assertSame($payload['author'], $validated['author']);
        $this->assertSame($payload['description'], $validated['description']);
        $this->assertSame($payload['genres'], $validated['genres']);
        $this->assertArrayNotHasKey('user_id', $validated);
        $this->assertSame($before, $this->DB状態());
    }

    public function test_更新時に自身の_isb_nと任意項目の未入力を許可する(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $user->id,
            'isbn' => '9781234567890',
        ]);

        $payload = [
            'title' => '更新用の書籍',
            'author' => 'テスト著者',
            'isbn' => $book->isbn,
            'published_date' => '2026-11-01',
            'description' => null,
            'image_url' => null,
            'genres' => [$genre->id],
            'user_id' => 2147483647,
        ];

        $before = $this->DB状態();

        $request = $this->検証用Request(
            UpdateBookRequest::class,
            'PUT',
            '/books/'.$book->id,
            $payload,
            $user,
            $book
        );

        $request->validateResolved();

        $validated = $request->validated();

        $this->assertSame($book->isbn, $validated['isbn']);
        $this->assertNull($validated['description']);
        $this->assertNull($validated['image_url']);
        $this->assertArrayNotHasKey('user_id', $validated);
        $this->assertSame($before, $this->DB状態());
    }

    public function test_一覧データは最新順で10件ずつ取得される(): void
    {
        $books = Book::factory()->count(11)->create([
            'created_at' => '2026-11-01 12:00:00',
        ]);

        // 同一日時の場合はID降順になることも確認する。
        $expectedIds = $books->pluck('id')->sortDesc()->values()->all();
        $controller = app(BookController::class);

        $view = $controller->index(Request::create('/'));

        $this->assertInstanceOf(View::class, $view);
        $this->assertSame('books.index', $view->name());

        $page = $view->getData()['books'];

        $this->assertSame(11, $page->total());
        $this->assertSame(10, $page->perPage());
        $this->assertSame(array_slice($expectedIds, 0, 10), $page->pluck('id')->all());

        foreach ($page as $book) {
            $this->assertTrue($book->relationLoaded('genres'));
        }

        $secondView = $controller->index(Request::create('/?page=2'));

        $this->assertInstanceOf(View::class, $secondView);

        $secondPage = $secondView->getData()['books'];

        $this->assertSame(2, $secondPage->currentPage());
        $this->assertSame(array_slice($expectedIds, 10), $secondPage->pluck('id')->all());
    }

    public function test_書籍0件の一覧データを取得できる(): void
    {
        $view = app(BookController::class)->index(Request::create('/'));

        $this->assertInstanceOf(View::class, $view);

        $books = $view->getData()['books'];

        $this->assertSame(0, $books->total());
        $this->assertTrue($books->isEmpty());
    }

    public function test_不正ページと範囲超過を補正する(): void
    {
        Book::factory()->count(11)->create();

        foreach (['0', '-1', 'abc', '1.5'] as $page) {
            $this->get('/?page='.$page)
                ->assertRedirect(route('books.index'));
        }

        $this->get('/?page[]=1')
            ->assertRedirect(route('books.index'));

        $this->get('/?page=999')
            ->assertRedirect(route('books.index', ['page' => 2]));
    }

    public function test_詳細データにジャンルとレビュー関連が読み込まれる(): void
    {
        $book = Book::factory()->create();

        $view = app(BookController::class)->show($book);

        $this->assertSame('books.show', $view->name());

        $loadedBook = $view->getData()['book'];

        $this->assertTrue($loadedBook->is($book));
        $this->assertTrue($loadedBook->relationLoaded('genres'));
        $this->assertTrue($loadedBook->relationLoaded('reviews'));

        foreach ($loadedBook->reviews as $review) {
            $this->assertTrue($review->relationLoaded('user'));
            $this->assertTrue($review->relationLoaded('likedByUsers'));
        }
    }

    /**
     * 本番ルートのパラメータを設定し、FormRequest自体を検証する。
     *
     * @param  class-string<StoreBookRequest>  $class
     * @param  array<string, mixed>  $payload
     */
    private function 検証用Request(
        string $class,
        string $method,
        string $uri,
        array $payload,
        User $user,
        ?Book $book = null
    ): StoreBookRequest {
        $httpRequest = Request::create($uri, $method, $payload);

        $route = app('router')->getRoutes()->match($httpRequest);
        $route->bind($httpRequest);

        if ($book !== null) {
            $route->setParameter('book', $book);
        }

        $request = $class::createFrom($httpRequest, new $class);

        $request->setContainer($this->app);
        $request->setRedirector(app('redirect'));
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
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
