<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BookAuthorizationTest extends TestCase
{
    public function test_ゲストは閲覧できるが登録更新削除はできない(): void
    {
        $book = new Book(['user_id' => 1]);
        $gate = Gate::forUser(null);

        $this->assertTrue($gate->allows('viewAny', Book::class));
        $this->assertTrue($gate->allows('view', $book));
        $this->assertFalse($gate->allows('create', Book::class));
        $this->assertFalse($gate->allows('update', $book));
        $this->assertFalse($gate->allows('delete', $book));
    }

    public function test_作成者は閲覧登録更新削除ができる(): void
    {
        $owner = new User;
        $owner->id = 1;

        $book = new Book(['user_id' => $owner->id]);
        $gate = Gate::forUser($owner);

        $this->assertTrue($gate->allows('viewAny', Book::class));
        $this->assertTrue($gate->allows('view', $book));
        $this->assertTrue($gate->allows('create', Book::class));
        $this->assertTrue($gate->allows('update', $book));
        $this->assertTrue($gate->allows('delete', $book));
    }

    public function test_他会員は閲覧登録できるが他人の書籍を更新削除できない(): void
    {
        $otherUser = new User;
        $otherUser->id = 2;

        $book = new Book(['user_id' => 1]);
        $gate = Gate::forUser($otherUser);

        $this->assertTrue($gate->allows('viewAny', Book::class));
        $this->assertTrue($gate->allows('view', $book));
        $this->assertTrue($gate->allows('create', Book::class));
        $this->assertFalse($gate->allows('update', $book));
        $this->assertFalse($gate->allows('delete', $book));
    }
}
