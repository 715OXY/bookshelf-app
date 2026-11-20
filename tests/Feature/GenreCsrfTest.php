<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenreCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            VerifyCsrfToken::class,
            EnforcedGenreCsrfMiddleware::class
        );
    }

    #[DataProvider('操作とトークン')]
    public function test_有効なトークンの場合だけジャンルを変更する(
        string $operation,
        ?string $token
    ): void {
        $user = User::factory()->create();

        $target = Genre::factory()->create([
            'name' => '操作対象ジャンル',
        ]);

        $remaining = Genre::factory()->create([
            'name' => '保持対象ジャンル',
        ]);

        $book = Book::factory()->for($user)->create();
        $book->genres()->sync([$remaining->id]);

        $before = $this->DB状態();

        $method = match ($operation) {
            'store' => 'POST',
            'update' => 'PUT',
            'destroy' => 'DELETE',
        };

        $url = match ($operation) {
            'store' => route('genres.store'),
            'update' => route('genres.update', $target),
            'destroy' => route('genres.destroy', $target),
        };

        $payload = $operation === 'destroy'
            ? []
            : ['name' => 'CSRF検証ジャンル'];

        if ($token !== null) {
            $payload['_token'] = $token;
        }

        $response = $this->actingAs($user, 'web')
            ->withSession([
                '_token' => 'expected-genre-token',
                'csrf_probe' => 'preserved',
            ])
            ->call($method, $url, $payload);

        $this->assertAuthenticatedAs($user, 'web');

        $response->assertSessionHas('csrf_probe', 'preserved');

        if ($token !== 'expected-genre-token') {
            $response->assertStatus(419)
                ->assertHeaderMissing('Location')
                ->assertSessionMissing('success');

            $this->assertSame($before, $this->DB状態());

            return;
        }

        $message = match ($operation) {
            'store' => 'ジャンルを登録しました。',
            'update' => 'ジャンルを更新しました。',
            'destroy' => 'ジャンルを削除しました。',
        };

        $response
            ->assertRedirect(route('genres.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', $message);

        if ($operation === 'store') {
            $this->assertDatabaseCount('genres', count($before['genres']) + 1);

            $this->assertDatabaseHas('genres', [
                'name' => 'CSRF検証ジャンル',
            ]);

            $this->assertDatabaseHas('genres', [
                'id' => $target->id,
                'name' => '操作対象ジャンル',
            ]);
        } elseif ($operation === 'update') {
            $this->assertDatabaseCount('genres', count($before['genres']));

            $this->assertDatabaseHas('genres', [
                'id' => $target->id,
                'name' => 'CSRF検証ジャンル',
            ]);
        } else {
            $this->assertDatabaseCount('genres', count($before['genres']) - 1);

            $this->assertDatabaseMissing('genres', [
                'id' => $target->id,
            ]);
        }

        $this->assertDatabaseHas('genres', [
            'id' => $remaining->id,
            'name' => '保持対象ジャンル',
        ]);

        $after = $this->DB状態();

        $genresAfter = array_column($after['genres'], null, 'id');

        foreach ($before['genres'] as $row) {
            if ($operation === 'store' || $row['id'] !== $target->id) {
                $this->assertSame($row, $genresAfter[$row['id']] ?? null);
            }
        }

        foreach ($before as $table => $rows) {
            if ($table !== 'genres') {
                $this->assertSame($rows, $after[$table]);
            }
        }
    }

    public static function 操作とトークン(): array
    {
        $cases = [];

        foreach (['登録' => 'store', '更新' => 'update', '削除' => 'destroy'] as $label => $operation) {
            $cases[$label.'でトークンなし'] = [$operation, null];
            $cases[$label.'で不一致'] = [$operation, 'wrong-token'];
            $cases[$label.'で有効トークン'] = [$operation, 'expected-genre-token'];
        }

        return $cases;
    }

    private function DB状態(): array
    {
        $snapshot = [];

        foreach ([
            'users',
            'books',
            'genres',
            'book_genres',
            'reviews',
            'favorites',
            'review_likes',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}

class EnforcedGenreCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
