<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenreWriteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('正常境界値')]
    public function test_正常境界値のジャンル名を保存できる(
        string $operation,
        int $length
    ): void {
        $this->actingAs(User::factory()->create(), 'web');

        $name = str_repeat('文', $length);

        if ($operation === 'store') {
            $response = $this->post(
                route('genres.store'),
                ['name' => $name]
            );

            $genre = Genre::query()->sole();
        } else {
            $genre = Genre::factory()->create([
                'name' => '更新前ジャンル',
            ]);

            $response = $this->put(
                route('genres.update', $genre),
                ['name' => $name]
            );

            $genre = $genre->fresh();
        }

        $response
            ->assertRedirect(route('genres.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('genres', 1);
        $this->assertSame($length, mb_strlen($genre->name));
        $this->assertSame($name, $genre->name);

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'name' => $name,
        ]);
    }

    #[DataProvider('必須入力の異常値')]
    public function test_ジャンル名未入力の更新を拒否する(
        array $input
    ): void {
        $this->actingAs(User::factory()->create(), 'web');

        $genre = Genre::factory()->create([
            'name' => '保存済みジャンル',
        ]);

        $before = $genre->fresh()->getRawOriginal();
        $url = route('genres.edit', $genre);

        $this->put(route('genres.update', $genre), $input)
            ->assertRedirect($url)
            ->assertSessionHasErrors([
                'name' => 'ジャンル名は必須です。',
            ])
            ->assertSessionHasInput('name', '')
            ->assertSessionMissing('success');

        $this->get($url)
            ->assertOk()
            ->assertSeeText('ジャンル名は必須です。')
            ->assertSee('value=""', false)
            ->assertDontSee('value="保存済みジャンル"', false);

        $this->assertDatabaseCount('genres', 1);

        $this->assertSame(
            $before,
            $genre->fresh()->getRawOriginal()
        );
    }

    public static function 正常境界値(): array
    {
        return [
            '登録1文字' => ['store', 1],
            '登録255文字' => ['store', 255],
            '更新1文字' => ['update', 1],
            '更新255文字' => ['update', 255],
        ];
    }

    public static function 必須入力の異常値(): array
    {
        return [
            '未送信' => [[]],
            'NULL' => [['name' => null]],
            '空文字' => [['name' => '']],
            '空白のみ' => [['name' => '   ']],
        ];
    }
}
