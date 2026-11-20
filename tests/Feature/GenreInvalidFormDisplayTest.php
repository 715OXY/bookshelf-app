<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenreInvalidFormDisplayTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('登録更新の操作')]
    public function test_配列入力を拒否してジャンルフォームを再表示する(
        string $operation
    ): void {
        $user = User::factory()->create();

        $genre = Genre::factory()->create([
            'name' => '保存済みジャンル',
        ]);

        $before = $this->DB状態();

        $this->actingAs($user, 'web');

        $formUrl = $operation === 'store'
            ? route('genres.create')
            : route('genres.edit', $genre);

        $payload = [
            'name' => ['不正な配列'],
        ];

        $this->from($formUrl);

        $response = $operation === 'store'
            ? $this->post(route('genres.store'), $payload)
            : $this->put(route('genres.update', $genre), $payload);

        $response
            ->assertRedirect($formUrl)
            ->assertSessionHasErrors([
                'name' => 'ジャンル名は文字列で入力してください。',
            ])
            ->assertSessionMissing('success');

        $this->assertSame($before, $this->DB状態());

        $form = $this->get($formUrl)
            ->assertOk()
            ->assertSeeText(
                'ジャンル名は文字列で入力してください。'
            );

        $document = new DOMDocument;
        @$document->loadHTML(
            '<?xml encoding="UTF-8">'.$form->getContent()
        );

        $xpath = new DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//input[@name="name"]')->length
        );

        $this->assertSame(
            '',
            $xpath->evaluate(
                'string(//input[@name="name"]/@value)'
            )
        );

        $this->assertSame($before, $this->DB状態());
    }

    public static function 登録更新の操作(): array
    {
        return [
            '登録' => ['store'],
            '更新' => ['update'],
        ];
    }

    private function DB状態(): array
    {
        $snapshot = [];

        foreach (['genres', 'books', 'book_genres'] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
