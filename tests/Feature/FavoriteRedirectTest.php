<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FavoriteRedirectTest extends TestCase
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

    #[DataProvider('操作元のケース')]
    public function test_操作元に応じた固定の戻り先へ遷移する(
        string $source,
        bool $returnToFavorites
    ): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create();

        $user->favoriteBooks()->attach($book->id);
        $otherUser->favoriteBooks()->attach($book->id);

        $origin = url('/');
        $parts = parse_url($origin);
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $differentPort = $port === 12345 ? 12346 : 12345;
        $differentScheme = $scheme === 'https' ? 'http' : 'https';

        $referer = match ($source) {
            '一覧' => $origin.'/favorites',
            '一覧2ページ目' => $origin.'/favorites?page=2',
            '書籍詳細' => route('books.show', $book),
            '外部ホスト' => $scheme.'://outside.invalid/favorites',
            '異なるスキーム' => $differentScheme.'://'.$host.'/favorites',
            '異なるポート' => $scheme.'://'.$host.':'.$differentPort.'/favorites',
            '別パス' => $origin.'/favorites-other',
            '相対URL' => '/favorites',
            'ユーザー情報付き' => $scheme.'://someone@'.$host.'/favorites',
            '未指定' => null,
        };

        $this->actingAs($user, 'web');

        if ($referer !== null) {
            $this->withHeader('Referer', $referer);
        }

        $response = $this->post(route('favorites.toggle', $book));

        $response
            ->assertRedirect(
                $returnToFavorites
                ? route('favorites.index')
                : route('books.show', $book)
            )
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $otherUser->id,
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseCount('favorites', 1);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function 操作元のケース(): array
    {
        return [
            '一覧へ戻る' => ['一覧', true],
            'ページを保持せず一覧へ戻る' => ['一覧2ページ目', true],
            '書籍詳細へ戻る' => ['書籍詳細', false],
            '外部ホストでは詳細へ戻る' => ['外部ホスト', false],
            '異なるスキームでは詳細へ戻る' => ['異なるスキーム', false],
            '異なるポートでは詳細へ戻る' => ['異なるポート', false],
            '別パスでは詳細へ戻る' => ['別パス', false],
            '相対URLでは詳細へ戻る' => ['相対URL', false],
            'ユーザー情報付きでは詳細へ戻る' => ['ユーザー情報付き', false],
            '未指定では詳細へ戻る' => ['未指定', false],
        ];
    }
}
