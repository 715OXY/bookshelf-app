<?php

namespace Tests\Feature;

use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ReviewValidationRulesTest extends TestCase
{
    /**
     * 投稿・更新で正常値と境界値を受け付ける。
     */
    public function test_投稿と更新で正常値と境界値を受け付ける(): void
    {
        $cases = [
            ['rating' => '1'],
            ['rating' => '5', 'comment' => null],
            ['rating' => '3', 'comment' => ''],
            ['rating' => 4, 'comment' => '読みやすい本でした。'],
            ['rating' => '5', 'comment' => str_repeat('あ', 10000)],
        ];

        foreach ([StoreReviewRequest::class, UpdateReviewRequest::class] as $class) {
            $request = new $class;

            foreach ($cases as $index => $input) {
                $validator = Validator::make(
                    $input,
                    $request->rules(),
                    $request->messages()
                );

                $this->assertTrue(
                    $validator->passes(),
                    "{$class}: 正常ケース{$index}"
                );
            }
        }
    }

    /**
     * 不正入力を拒否し、対応する日本語メッセージを返す。
     */
    public function test_不正入力を拒否し対応する日本語メッセージを返す(): void
    {
        $cases = [
            [[], 'rating', '評価を選択してください。'],
            [['rating' => null], 'rating', '評価を選択してください。'],
            [['rating' => ''], 'rating', '評価を選択してください。'],
            [['rating' => '0'], 'rating', '評価は1〜5で選択してください。'],
            [['rating' => '6'], 'rating', '評価は1〜5で選択してください。'],
            [['rating' => '1.5'], 'rating', '評価は整数で指定してください。'],
            [['rating' => 'abc'], 'rating', '評価は整数で指定してください。'],
            [['rating' => ['5']], 'rating', '評価は整数で指定してください。'],
            [
                ['rating' => '3', 'comment' => ['感想']],
                'comment',
                'コメントは文字列で入力してください。',
            ],
            [
                ['rating' => '3', 'comment' => 123],
                'comment',
                'コメントは文字列で入力してください。',
            ],
            [
                ['rating' => '3', 'comment' => str_repeat('あ', 10001)],
                'comment',
                'コメントは10,000文字以下で入力してください。',
            ],
        ];

        foreach ([StoreReviewRequest::class, UpdateReviewRequest::class] as $class) {
            $request = new $class;

            foreach ($cases as $index => [$input, $field, $message]) {
                $validator = Validator::make(
                    $input,
                    $request->rules(),
                    $request->messages()
                );

                $this->assertTrue(
                    $validator->fails(),
                    "{$class}: 異常ケース{$index}"
                );

                $this->assertSame(
                    $message,
                    $validator->errors()->first($field),
                    "{$class}: 異常ケース{$index}の文言"
                );
            }
        }
    }
}
