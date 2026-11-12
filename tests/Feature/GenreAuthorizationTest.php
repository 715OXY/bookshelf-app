<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\User;
use App\Policies\GenrePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class GenreAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * GenrePolicyが認可システムに登録されていることを確認する。
     */
    public function test_ジャンルに対応する_policyが解決される(): void
    {
        $this->assertInstanceOf(
            GenrePolicy::class,
            Gate::getPolicyFor(Genre::class)
        );
    }

    /**
     * ゲストにはジャンルの各操作を許可しない。
     */
    public function test_ゲストはジャンル操作を許可されない(): void
    {
        $genre = new Genre;
        $gate = Gate::forUser(null);

        $this->assertFalse($gate->allows('viewAny', Genre::class));
        $this->assertFalse($gate->allows('view', $genre));
        $this->assertFalse($gate->allows('create', Genre::class));
        $this->assertFalse($gate->allows('update', $genre));
        $this->assertFalse($gate->allows('delete', $genre));
    }

    /**
     * 認証済み会員にジャンル操作を許可する。
     */
    public function test_認証済み会員はジャンル操作を許可される(): void
    {
        $user = (new User)->forceFill(['id' => 1]);
        $genre = new Genre;
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', Genre::class));
        $this->assertTrue($gate->allows('view', $genre));
        $this->assertTrue($gate->allows('create', Genre::class));
        $this->assertTrue($gate->allows('update', $genre));
        $this->assertTrue($gate->allows('delete', $genre));
    }

    /**
     * 未認証では全ジャンルルートからログインへ誘導する。
     */
    public function test_ゲストはジャンル管理ルートへアクセスできない(): void
    {
        $genre = Genre::factory()->create();

        $requests = [
            ['GET', route('genres.index')],
            ['GET', route('genres.show', $genre)],
            ['GET', route('genres.create')],
            ['POST', route('genres.store')],
            ['GET', route('genres.edit', $genre)],
            ['PUT', route('genres.update', $genre)],
            ['DELETE', route('genres.destroy', $genre)],
        ];

        foreach ($requests as [$method, $url]) {
            $this->call($method, $url)
                ->assertRedirect(route('login'));
        }
    }

    /**
     * 存在しないジャンルの詳細・編集・更新・削除は404とする。
     */
    public function test_存在しないジャンルへのアクセスは404になる(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $missingId = 999999;

        $this->get(route('genres.show', $missingId))->assertNotFound();
        $this->get(route('genres.edit', $missingId))->assertNotFound();
        $this->put(route('genres.update', $missingId))->assertNotFound();
        $this->delete(route('genres.destroy', $missingId))->assertNotFound();
    }
}
