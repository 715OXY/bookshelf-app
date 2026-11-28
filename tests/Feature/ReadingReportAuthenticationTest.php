<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReadingReportAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'http://localhost']);
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

    // H01
    public function test_未認証では会員データを返さずログインへ誘導する(): void
    {
        $user = User::factory()->create(['name' => '非公開の会員名']);
        $book = Book::factory()->for($user)->create(['title' => '非公開の読書履歴']);
        Review::factory()->for($user)->for($book)->create(['rating' => 5]);

        $response = $this->get('/reports')
            ->assertRedirect(route('login'))
            ->assertDontSee('非公開の会員名')
            ->assertDontSee('非公開の読書履歴');

        $this->assertNotInstanceOf(View::class, $response->original);
        $this->assertGuest('web');
    }

    // H03
    public function test_外部の会員指定を無視して認証済み本人だけを集計する(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $genre = Genre::factory()->create(['name' => '本人のジャンル']);
        $otherGenre = Genre::factory()->create(['name' => '他会員のジャンル']);
        $book = Book::factory()->for($other)->create(['title' => '本人の対象書籍', 'author' => '本人の対象著者']);
        $otherBook = Book::factory()->for($user)->create();
        $book->genres()->sync([$genre->id]);
        $otherBook->genres()->sync([$otherGenre->id]);
        Review::factory()->for($user)->for($book)->create(['rating' => 4]);
        Review::factory()->for($other)->for($otherBook)->count(2)->create(['rating' => 5]);
        $this->actingAs($user, 'web');

        foreach ([
            ['user_id' => $other->id],
            ['user' => $other->id],
            ['member_id' => $other->id],
            ['user_id' => $other->id, 'user' => $other->id, 'member_id' => $other->id],
        ] as $query) {
            $response = $this->get(route('reports.index', $query))
                ->assertOk()
                ->assertViewIs('reports.index');
            $stats = $response->viewData('stats');

            $this->assertSame(['summary', 'rating_distribution', 'top_rated_books', 'genre_ratings'], array_keys($stats));
            $this->assertSame(['total_reviews' => 1, 'books_read' => 1, 'average_rating' => 4.0], $stats['summary']);
            $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
            $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
            $this->assertSame([0, 0, 0, 1, 0], $stats['rating_distribution']->all());
            $this->assertSame([
                ['id' => $book->id, 'title' => '本人の対象書籍', 'author' => '本人の対象著者', 'rating' => 4],
            ], $stats['top_rated_books']);
            $this->assertSame([
                ['id' => $genre->id, 'name' => '本人のジャンル', 'count' => 1, 'average_rating' => 4.0],
            ], $stats['genre_ratings']);
            $this->assertAuthenticatedAs($user, 'web');
        }
    }

    // H04
    public function test_正式なログイン後に元の本人レポートへ復帰する(): void
    {
        $user = User::factory()->create([
            'email' => 'report@example.com',
            'password' => Hash::make('Example123!'),
        ]);
        $book = Book::factory()->for($user)->create();
        $book->genres()->detach();
        Review::factory()->for($user)->for($book)->create(['rating' => 4]);
        $destination = route('reports.index');

        $this->get($destination)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $destination);
        $this->assertGuest('web');
        $this->get(route('login'))->assertOk()->assertViewIs('auth.login');

        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'report@example.com',
            'password' => 'Example123!',
        ])->assertRedirect($destination)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user, 'web');
        $response = $this->get($destination)->assertOk()->assertViewIs('reports.index');
        $this->assertSame(['total_reviews' => 1, 'books_read' => 1, 'average_rating' => 4.0], $response->viewData('stats')['summary']);
        $this->assertSame([$book->id], array_column($response->viewData('stats')['top_rated_books'], 'id'));
    }
}
