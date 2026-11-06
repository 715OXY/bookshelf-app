<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
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

    public function test_指定の初期データを依存順に一括投入できる(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('genres', 10);
        $this->assertDatabaseCount('books', 11);
        $this->assertDatabaseCount('reviews', 32);

        $expectedUsers = [
            'yamada@example.com' => '山田太郎',
            'suzuki@example.com' => '鈴木花子',
            'tanaka@example.com' => '田中一郎',
            'sato@example.com' => '佐藤美咲',
            'takahashi@example.com' => '高橋健太',
        ];

        foreach ($expectedUsers as $email => $name) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame($name, $user->name);
            $this->assertNotSame('password', $user->password);
            $this->assertTrue(Hash::check('password', $user->password));
        }

        $this->assertEqualsCanonicalizing(
            [
                '小説',
                'ビジネス',
                '技術書',
                '自己啓発',
                'エッセイ',
                '歴史',
                '科学',
                '芸術',
                '料理',
                '旅行',
            ],
            Genre::pluck('name')->all()
        );

        $owner = User::firstOrFail();

        $this->assertSame('yamada@example.com', $owner->email);

        // タイトル、著者、ISBN、出版日、ジャンルの順。
        $expectedBooks = [
            [
                '吾輩は猫である',
                '夏目漱石',
                '9784101010014',
                '1905-01-01',
                ['小説'],
            ],
            [
                '人を動かす',
                'D・カーネギー',
                '9784422100524',
                '1936-10-01',
                ['ビジネス', '自己啓発'],
            ],
            [
                'リーダブルコード',
                'Dustin Boswell',
                '9784873115658',
                '2012-06-23',
                ['技術書'],
            ],
            [
                '7つの習慣',
                'スティーブン・R・コヴィー',
                '9784863940246',
                '2013-08-30',
                ['ビジネス', '自己啓発'],
            ],
            [
                '坊っちゃん',
                '夏目漱石',
                '9784101010021',
                '1906-04-01',
                ['小説'],
            ],
            [
                'サピエンス全史',
                'ユヴァル・ノア・ハラリ',
                '9784309226712',
                '2016-09-08',
                ['歴史', '科学'],
            ],
            [
                'Clean Code',
                'Robert C. Martin',
                '9784048930598',
                '2017-12-18',
                ['技術書'],
            ],
            [
                '嫌われる勇気',
                '岸見一郎・古賀史健',
                '9784478025819',
                '2013-12-13',
                ['自己啓発'],
            ],
            [
                '火花',
                '又吉直樹',
                '9784163902302',
                '2015-03-11',
                ['小説'],
            ],
            [
                'FACTFULNESS',
                'ハンス・ロスリング',
                '9784822289607',
                '2019-01-11',
                ['ビジネス', '科学'],
            ],
            [
                'コンテナ物語',
                'マルク・レビンソン',
                '9784822251468',
                '2007-01-18',
                ['ビジネス', '歴史'],
            ],
        ];

        foreach ($expectedBooks as $index => $data) {
            [$title, $author, $isbn, $publishedDate, $genres] = $data;
            $book = Book::where('isbn', $isbn)->firstOrFail();
            $number = $index + 1;

            $this->assertSame($title, $book->title);
            $this->assertSame($author, $book->author);
            $this->assertSame($owner->id, $book->user_id);
            $this->assertSame(
                $publishedDate,
                $book->published_date->format('Y-m-d')
            );
            $this->assertNotEmpty($book->description);
            $this->assertSame(
                "https://placehold.co/200x300/e2e8f0/475569?text={$number}",
                $book->image_url
            );
            $this->assertEqualsCanonicalizing(
                $genres,
                $book->genres()->pluck('name')->all()
            );

            $reviewCount = $book->reviews()->count();

            $this->assertGreaterThanOrEqual(2, $reviewCount);
            $this->assertLessThanOrEqual(4, $reviewCount);
        }

        foreach (User::all() as $user) {
            $this->assertGreaterThan(0, $user->reviews()->count());

            $favoriteCount = $user->favoriteBooks()->count();

            $this->assertGreaterThanOrEqual(3, $favoriteCount);
            $this->assertLessThanOrEqual(5, $favoriteCount);
        }

        foreach (Review::with('likedByUsers')->get() as $review) {
            $this->assertGreaterThanOrEqual(3, $review->rating);
            $this->assertLessThanOrEqual(5, $review->rating);
            $this->assertNotEmpty($review->comment);
            $this->assertLessThanOrEqual(3, $review->likedByUsers->count());
            $this->assertFalse(
                $review->likedByUsers->contains('id', $review->user_id)
            );
        }
    }
}
