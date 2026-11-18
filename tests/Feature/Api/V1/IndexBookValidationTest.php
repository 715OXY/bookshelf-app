<?php

namespace Tests\Feature\Api\V1;

use App\Models\Genre;
use PHPUnit\Framework\Attributes\DataProvider;

class IndexBookValidationTest extends BookValidationTestCase
{
    public function test_未認証で一覧の入力を省略できる(): void
    {
        $this->getJson(self::URI)->assertOk();
        $this->assertGuest();
    }

    public function test_一覧の正常な検索条件を受け付ける(): void
    {
        $genre = Genre::factory()->create();

        $this->getJson(self::URI.'?'.http_build_query([
            'keyword' => '文学',
            'genre_id' => $genre->id,
            'page' => 1,
            'per_page' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('keyword', '文学');
    }

    #[DataProvider('invalidIndexInputs')]
    public function test_一覧の不正な入力を拒否する(
        string $field,
        mixed $value
    ): void {
        $this->getJson(self::URI.'?'.http_build_query([
            $field => $value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public static function invalidIndexInputs(): array
    {
        return [
            'キーワードの型不正' => ['keyword', ['invalid']],
            'キーワードの上限超過' => ['keyword', str_repeat('あ', 256)],
            'ジャンルIDの型不正' => ['genre_id', 'invalid'],
            'ジャンル不存在' => ['genre_id', 2147483647],
            'ページ番号ゼロ' => ['page', 0],
            'ページ番号負数' => ['page', -1],
            'ページ番号小数' => ['page', '1.5'],
            'ページ番号上限超過' => ['page', '2147483648'],
            'ページ件数ゼロ' => ['per_page', 0],
            'ページ件数上限超過' => ['per_page', 101],
            'ページ件数の型不正' => ['per_page', 'invalid'],
        ];
    }

    public function test_acceptヘッダーなしでも日本語の_jso_nエラーを返す(): void
    {
        $this->get(self::URI.'?page=0')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonPath(
                'errors.page.0',
                'ページ番号は1以上で入力してください。'
            );
    }
}
