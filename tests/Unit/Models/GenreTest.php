<?php

namespace Tests\Unit\Models;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GenreTest extends ModelTestCase
{
    public function test_許可された属性だけを一括代入できる(): void
    {
        $genre = new Genre([
            'name' => '技術書',
            'id' => 999,
        ]);

        $this->assertSame(['name'], $genre->getFillable());
        $this->assertSame('技術書', $genre->name);
        $this->assertNull($genre->id);
    }

    public function test_書籍との多対多関連が定義されている(): void
    {
        $relation = (new Genre)->books();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(Book::class, $relation->getRelated());
        $this->assertSame('book_genres', $relation->getTable());
        $this->assertSame('genre_id', $relation->getForeignPivotKeyName());
        $this->assertSame('book_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());
    }
}
