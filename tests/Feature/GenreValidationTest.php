<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_登録時の必須入力を検証する(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('genres.store'), [])
            ->assertRedirect(route('genres.create'))
            ->assertSessionHasErrors([
                'name' => 'ジャンル名を入力してください。',
            ]);

        $this->assertDatabaseCount('genres', 0);
    }

    public function test_登録時の文字列型を検証する(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('genres.store'), [
            'name' => ['invalid'],
        ])
            ->assertRedirect(route('genres.create'))
            ->assertSessionHasErrors([
                'name' => 'ジャンル名は文字列で入力してください。',
            ]);

        $this->assertDatabaseCount('genres', 0);
    }

    public function test_登録時の255文字制限を検証する(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('genres.store'), [
            'name' => str_repeat('a', 256),
        ])
            ->assertRedirect(route('genres.create'))
            ->assertSessionHasErrors([
                'name' => 'ジャンル名は255文字以下で入力してください。',
            ]);

        $this->assertDatabaseCount('genres', 0);
    }

    public function test_登録時の重複を拒否する(): void
    {
        $this->actingAs(User::factory()->create());

        Genre::factory()->create(['name' => '技術書']);

        $this->post(route('genres.store'), [
            'name' => '技術書',
        ])
            ->assertRedirect(route('genres.create'))
            ->assertSessionHasErrors([
                'name' => 'このジャンル名は既に登録されています。',
            ])
            ->assertSessionHasInput('name', '技術書');

        $this->assertDatabaseCount('genres', 1);
    }

    public function test_更新時の重複を拒否して元の値を保持する(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create(['name' => '技術書']);
        Genre::factory()->create(['name' => '文学']);

        $this->put(route('genres.update', $genre), [
            'name' => '文学',
        ])
            ->assertRedirect(route('genres.edit', $genre))
            ->assertSessionHasErrors([
                'name' => 'このジャンル名は既に登録されています。',
            ])
            ->assertSessionHasInput('name', '文学');

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'name' => '技術書',
        ]);
    }

    public function test_更新時の文字数超過で元の値を保持する(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create(['name' => '技術書']);
        $invalidName = str_repeat('a', 256);

        $this->put(route('genres.update', $genre), [
            'name' => $invalidName,
        ])
            ->assertRedirect(route('genres.edit', $genre))
            ->assertSessionHasErrors([
                'name' => 'ジャンル名は255文字以下で入力してください。',
            ])
            ->assertSessionHasInput('name', $invalidName);

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'name' => '技術書',
        ]);
    }
}
