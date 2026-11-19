<?php

namespace Tests\Unit\Models;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class BookTest extends ModelTestCase
{
    public function test_許可された属性だけを一括代入できる(): void
    {
        $book = new Book([
            'user_id' => 1,
            'title' => 'テスト書籍',
            'author' => 'テスト著者',
            'isbn' => '9784873115658',
            'published_date' => '2012-06-23',
            'description' => 'テスト用の説明',
            'image_url' => 'https://example.com/book.jpg',
            'id' => 999,
        ]);

        $this->assertSame([
            'user_id',
            'title',
            'author',
            'isbn',
            'published_date',
            'description',
            'image_url',
        ], $book->getFillable());

        $this->assertSame(1, $book->user_id);
        $this->assertSame('テスト書籍', $book->title);
        $this->assertSame('テスト著者', $book->author);
        $this->assertSame('9784873115658', $book->isbn);
        $this->assertSame('2012-06-23', $book->published_date->format('Y-m-d'));
        $this->assertSame('テスト用の説明', $book->description);
        $this->assertSame('https://example.com/book.jpg', $book->image_url);
        $this->assertNull($book->id);
    }

    public function test_出版日は日付型に変換される(): void
    {
        $book = new Book([
            'published_date' => '2012-06-23',
        ]);

        $this->assertInstanceOf(Carbon::class, $book->published_date);

        $this->assertSame(
            '2012-06-23 00:00:00',
            $book->published_date->format('Y-m-d H:i:s')
        );
    }

    public function test_登録者との多対一関連が定義されている(): void
    {
        $relation = (new Book)->user();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(User::class, $relation->getRelated());
        $this->assertSame('user_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getOwnerKeyName());
    }

    public function test_レビューとの一対多関連が定義されている(): void
    {
        $relation = (new Book)->reviews();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(Review::class, $relation->getRelated());
        $this->assertSame('book_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getLocalKeyName());
    }

    public function test_ジャンルとの多対多関連が定義されている(): void
    {
        $relation = (new Book)->genres();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(Genre::class, $relation->getRelated());
        $this->assertSame('book_genres', $relation->getTable());
        $this->assertSame('book_id', $relation->getForeignPivotKeyName());
        $this->assertSame('genre_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());
    }

    public function test_お気に入り登録者との多対多関連が定義されている(): void
    {
        $relation = (new Book)->favoritedByUsers();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(User::class, $relation->getRelated());
        $this->assertSame('favorites', $relation->getTable());
        $this->assertSame('book_id', $relation->getForeignPivotKeyName());
        $this->assertSame('user_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());
    }
}
