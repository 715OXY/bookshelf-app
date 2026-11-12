<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FavoriteToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_追加解除再追加で登録状態が切り替わる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user, 'web');

        foreach ([1, 0, 1] as $expectedCount) {
            $this->post(route('favorites.toggle', $book))
                ->assertRedirect(route('books.show', $book))
                ->assertSessionHasNoErrors();

            $this->assertDatabaseCount('favorites', $expectedCount);
        }

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
    }

    public function test_自分が登録した書籍もお気に入りにできる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->post(route('favorites.toggle', $book))
            ->assertRedirect(route('books.show', $book));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
    }

    public function test_送信されたidを使わず他の関連を保持する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $otherUser->id]);
        $otherBook = Book::factory()->create();

        $otherUser->favoriteBooks()->attach($book->id);
        $user->favoriteBooks()->attach($otherBook->id);

        $this->actingAs($user, 'web');

        foreach ([true, false] as $registered) {
            $this->post(route('favorites.toggle', $book), [
                'user_id' => $otherUser->id,
                'book_id' => $otherBook->id,
            ])->assertRedirect(route('books.show', $book));

            $target = [
                'user_id' => $user->id,
                'book_id' => $book->id,
            ];

            if ($registered) {
                $this->assertDatabaseHas('favorites', $target);
            } else {
                $this->assertDatabaseMissing('favorites', $target);
            }

            $this->assertDatabaseHas('favorites', [
                'user_id' => $otherUser->id,
                'book_id' => $book->id,
            ]);
            $this->assertDatabaseHas('favorites', [
                'user_id' => $user->id,
                'book_id' => $otherBook->id,
            ]);
            $this->assertDatabaseCount('favorites', $registered ? 3 : 2);
        }
    }

    public function test_ゲストの要求ではお気に入りを変更しない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $user->favoriteBooks()->attach($book->id);

        $this->post(route('favorites.toggle', $book))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('favorites', 1);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
    }

    public function test_削除された書籍への要求は404となり他のお気に入りを保持する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $book = Book::factory()->create();
        $bookId = $book->id;
        $book->delete();

        $otherBook = Book::factory()->create();

        $user->favoriteBooks()->attach($otherBook->id);
        $otherUser->favoriteBooks()->attach($otherBook->id);

        $before = DB::table('favorites')
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->actingAs($user, 'web')
            ->post('/books/'.$bookId.'/favorites')
            ->assertNotFound()
            ->assertHeaderMissing('Location')
            ->assertSessionMissing('success');

        $this->assertEquals(
            $before,
            DB::table('favorites')->orderBy('id')->get()->toArray()
        );
    }
}
