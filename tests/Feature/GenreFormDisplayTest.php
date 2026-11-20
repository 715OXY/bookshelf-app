<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreFormDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_フォームの初期値と送信先とキャンセル先を表示する(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create(['name' => '編集前ジャンル']);

        $this->get(route('genres.create'))
            ->assertOk()
            ->assertSee('action="'.route('genres.store').'"', false)
            ->assertSee('value=""', false)
            ->assertSee('href="'.route('genres.index').'"', false)
            ->assertSeeText('キャンセル');

        $this->get(route('genres.edit', $genre))
            ->assertOk()
            ->assertSee('action="'.route('genres.update', $genre).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="編集前ジャンル"', false)
            ->assertSee('href="'.route('genres.index').'"', false)
            ->assertSeeText('キャンセル');
    }

    public function test_登録と更新の重複エラー後に入力値と項目エラーを表示する(): void
    {
        $this->actingAs(User::factory()->create());
        Genre::factory()->create(['name' => '重複ジャンル']);
        $genre = Genre::factory()->create(['name' => '変更前ジャンル']);

        foreach ([
            ['POST', route('genres.store'), route('genres.create')],
            ['PUT', route('genres.update', $genre), route('genres.edit', $genre)],
        ] as [$method, $url, $formUrl]) {
            $this->call($method, $url, ['name' => '重複ジャンル'])
                ->assertRedirect($formUrl)
                ->assertSessionHasErrors('name')
                ->assertSessionHasInput('name', '重複ジャンル');

            $this->get($formUrl)
                ->assertOk()
                ->assertSee('value="重複ジャンル"', false)
                ->assertSeeText('そのジャンル名は既に使用されています。');
        }

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'name' => '変更前ジャンル',
        ]);
    }

    public function test_登録更新削除後に一覧へ遷移して成功通知を表示する(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('genres.store'), ['name' => '通知確認ジャンル'])
            ->assertRedirect(route('genres.index'));

        $this->get(route('genres.index'))
            ->assertOk()
            ->assertSeeText('ジャンルを登録しました。')
            ->assertSeeText('通知確認ジャンル');

        $genre = Genre::where('name', '通知確認ジャンル')->firstOrFail();

        $this->put(route('genres.update', $genre), ['name' => '更新後ジャンル'])
            ->assertRedirect(route('genres.index'));

        $this->get(route('genres.index'))
            ->assertOk()
            ->assertSeeText('ジャンルを更新しました。')
            ->assertSeeText('更新後ジャンル');

        $this->delete(route('genres.destroy', $genre))
            ->assertRedirect(route('genres.index'));

        $this->get(route('genres.index'))
            ->assertOk()
            ->assertSeeText('ジャンルを削除しました。')
            ->assertDontSeeText('更新後ジャンル');
    }

    public function test_書籍が紐付くジャンルの削除拒否通知を一覧に表示する(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create(['name' => '削除拒否確認ジャンル']);
        $book = Book::factory()->create();
        $book->genres()->sync([$genre->id]);

        $this->delete(route('genres.destroy', $genre))
            ->assertRedirect(route('genres.index'));

        $this->get(route('genres.index'))
            ->assertOk()
            ->assertSeeText('書籍が紐付いているため、このジャンルは削除できません。')
            ->assertSeeText($genre->name);

        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $genre->id,
        ]);
    }
}
