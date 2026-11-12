<?php

namespace Tests\Feature;

use App\Http\Controllers\FavoriteController;
use App\Models\Book;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FavoriteIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());

        $result = $connection->selectOne(
            'SELECT DATABASE() AS database_name'
        );

        $this->assertSame('testing', $result->database_name);
    }

    public function test_本人のお気に入りだけを10件ずつ取得する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $books = Book::factory()->count(11)->create();

        foreach ($books as $book) {
            $user->favoriteBooks()->attach($book->id, [
                'created_at' => '2026-11-01 12:00:00',
                'updated_at' => '2026-11-01 12:00:00',
            ]);
        }

        $otherBook = Book::factory()->create();
        $otherUser->favoriteBooks()->attach($otherBook->id);

        // 誰もお気に入りにしていない書籍も取得対象外とする。
        Book::factory()->create();

        $expectedIds = $books->pluck('id')
            ->sortDesc()->values()->all();

        $firstPage = $this->一覧データ($user, 1);
        $secondPage = $this->一覧データ($user, 2);

        $this->assertSame(11, $firstPage->total());
        $this->assertSame(10, $firstPage->perPage());
        $this->assertSame(1, $firstPage->currentPage());
        $this->assertSame(
            array_slice($expectedIds, 0, 10),
            $firstPage->pluck('id')->all()
        );

        $this->assertSame(2, $secondPage->currentPage());
        $this->assertSame(
            array_slice($expectedIds, 10),
            $secondPage->pluck('id')->all()
        );
    }

    public function test_書籍作成日時ではなくお気に入り登録日時の降順になる(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(2)->create();

        $user->favoriteBooks()->attach($books[0]->id, [
            'created_at' => '2026-11-02 12:00:00',
            'updated_at' => '2026-11-02 12:00:00',
        ]);
        $user->favoriteBooks()->attach($books[1]->id, [
            'created_at' => '2026-11-01 12:00:00',
            'updated_at' => '2026-11-01 12:00:00',
        ]);

        $this->assertSame(
            [$books[0]->id, $books[1]->id],
            $this->一覧データ($user)->pluck('id')->all()
        );
    }

    public function test_お気に入り0件でも通常の一覧データを返す(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create();

        $otherUser->favoriteBooks()->attach($book->id);

        $page = $this->一覧データ($user);

        $this->assertSame(0, $page->total());
        $this->assertSame(1, $page->lastPage());
        $this->assertTrue($page->isEmpty());
    }

    public function test_不正ページは先頭へ戻す(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');

        foreach (['0', '-1', 'abc', '1.5'] as $page) {
            $this->get('/favorites?page='.$page)
                ->assertRedirect(route('favorites.index'));
        }

        $this->get('/favorites?page[]=1')
            ->assertRedirect(route('favorites.index'));
    }

    public function test_範囲超過は本人の最終有効ページへ戻す(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(11)->create();

        $user->favoriteBooks()->attach($books->modelKeys());

        $this->actingAs($user, 'web')
            ->get('/favorites?page=999')
            ->assertRedirect(route('favorites.index', ['page' => 2]));
    }

    public function test_お気に入り0件で範囲超過なら先頭へ戻す(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get('/favorites?page=2')
            ->assertRedirect(route('favorites.index'));
    }

    public function test_ゲストはログインへ誘導される(): void
    {
        $this->get('/favorites')
            ->assertRedirect(route('login'));
    }

    private function 一覧データ(
        User $user,
        int $page = 1
    ): LengthAwarePaginator {
        $request = Request::create('/favorites', 'GET', [
            'page' => $page,
        ]);

        $request->setUserResolver(fn () => $user);

        $view = app(FavoriteController::class)->index($request);

        $this->assertInstanceOf(View::class, $view);
        $this->assertSame('favorites.index', $view->name());

        $books = $view->getData()['books'];

        $this->assertInstanceOf(LengthAwarePaginator::class, $books);

        return $books;
    }
}
