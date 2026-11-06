<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class BookSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if ($user === null) {
            throw new RuntimeException('先にUserSeederを実行してください。');
        }

        // タイトル、著者、ISBN、出版日、説明、ジャンルの順。
        $books = [
            [
                '吾輩は猫である',
                '夏目漱石',
                '9784101010014',
                '1905-01-01',
                '猫の視点から人間社会を描く、夏目漱石の小説。',
                ['小説'],
            ],
            [
                '人を動かす',
                'D・カーネギー',
                '9784422100524',
                '1936-10-01',
                '人間関係やコミュニケーションの原則を紹介する一冊。',
                ['ビジネス', '自己啓発'],
            ],
            [
                'リーダブルコード',
                'Dustin Boswell',
                '9784873115658',
                '2012-06-23',
                '理解しやすいコードを書くための考え方と実践を紹介する。',
                ['技術書'],
            ],
            [
                '7つの習慣',
                'スティーブン・R・コヴィー',
                '9784863940246',
                '2013-08-30',
                '日々の行動や人との関わりを見直すための習慣を紹介する。',
                ['ビジネス', '自己啓発'],
            ],
            [
                '坊っちゃん',
                '夏目漱石',
                '9784101010021',
                '1906-04-01',
                '教師として赴任した青年が巻き起こす騒動を描く小説。',
                ['小説'],
            ],
            [
                'サピエンス全史',
                'ユヴァル・ノア・ハラリ',
                '9784309226712',
                '2016-09-08',
                '人類の歩みを歴史と科学の視点からたどる。',
                ['歴史', '科学'],
            ],
            [
                'Clean Code',
                'Robert C. Martin',
                '9784048930598',
                '2017-12-18',
                '保守しやすいコードを書くための原則と実践を紹介する。',
                ['技術書'],
            ],
            [
                '嫌われる勇気',
                '岸見一郎・古賀史健',
                '9784478025819',
                '2013-12-13',
                '対話を通してアドラー心理学の考え方を紹介する。',
                ['自己啓発'],
            ],
            [
                '火花',
                '又吉直樹',
                '9784163902302',
                '2015-03-11',
                '芸人たちの交流と葛藤を描く小説。',
                ['小説'],
            ],
            [
                'FACTFULNESS',
                'ハンス・ロスリング',
                '9784822289607',
                '2019-01-11',
                'データを基に世界を捉えるための考え方を紹介する。',
                ['ビジネス', '科学'],
            ],
            [
                'コンテナ物語',
                'マルク・レビンソン',
                '9784822251468',
                '2007-01-18',
                'コンテナ輸送の発展と世界経済への影響をたどる。',
                ['ビジネス', '歴史'],
            ],
        ];

        foreach ($books as $index => $data) {
            [$title, $author, $isbn, $publishedDate, $description, $genreNames] = $data;

            $genreIds = [];

            foreach ($genreNames as $name) {
                $genreIds[] = Genre::where('name', $name)->firstOrFail()->id;
            }

            $number = $index + 1;

            $book = Book::firstOrCreate(
                ['isbn' => $isbn],
                [
                    'user_id' => $user->id,
                    'title' => $title,
                    'author' => $author,
                    'published_date' => $publishedDate,
                    'description' => $description,
                    'image_url' => "https://placehold.co/200x300/e2e8f0/475569?text={$number}",
                ]
            );

            $book->genres()->sync($genreIds);
        }
    }
}
