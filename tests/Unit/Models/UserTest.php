<?php

namespace Tests\Unit\Models;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class UserTest extends ModelTestCase
{
    public function test_許可された属性だけを一括代入できる(): void
    {
        $user = new User([
            'name' => 'テストユーザー',
            'email' => 'unit@example.com',
            'password' => 'Example123!',
            'id' => 999,
            'remember_token' => 'untrusted-token',
            'two_factor_secret' => 'untrusted-secret',
        ]);

        $this->assertSame(
            ['name', 'email', 'password'],
            $user->getFillable()
        );

        $this->assertSame('テストユーザー', $user->name);
        $this->assertSame('unit@example.com', $user->email);
        $this->assertTrue(Hash::check('Example123!', $user->password));
        $this->assertNull($user->id);
        $this->assertNull($user->remember_token);
        $this->assertNull($user->two_factor_secret);
    }

    public function test_機密属性は配列とjsonに含まれない(): void
    {
        $user = new User([
            'name' => 'テストユーザー',
            'email' => 'unit@example.com',
            'password' => 'Example123!',
        ]);

        $user->forceFill([
            'remember_token' => 'test-token',
            'two_factor_secret' => 'test-secret',
            'two_factor_recovery_codes' => 'test-codes',
        ]);

        $array = $user->toArray();
        $json = json_decode(
            $user->toJson(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ] as $attribute) {
            $this->assertArrayNotHasKey($attribute, $array);
            $this->assertArrayNotHasKey($attribute, $json);
        }

        $this->assertSame('unit@example.com', $array['email']);
        $this->assertSame('unit@example.com', $json['email']);
    }

    public function test_平文パスワードは自動的にハッシュ化される(): void
    {
        $user = new User;

        $user->password = 'Example123!';

        $this->assertNotSame('Example123!', $user->password);
        $this->assertTrue(Hash::check('Example123!', $user->password));
    }

    public function test_ハッシュ化済みパスワードは再ハッシュ化されない(): void
    {
        $hashed = Hash::make('Example123!');

        $user = new User;
        $user->password = $hashed;

        $this->assertSame($hashed, $user->password);
        $this->assertTrue(Hash::check('Example123!', $user->password));
    }

    public function test_認証関連の日時属性は日時型に変換される(): void
    {
        $user = new User;

        $user->forceFill([
            'email_verified_at' => '2026-11-19 10:30:00',
            'two_factor_confirmed_at' => '2026-11-19 12:30:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $user->email_verified_at);
        $this->assertInstanceOf(Carbon::class, $user->two_factor_confirmed_at);

        $this->assertSame(
            '2026-11-19 10:30:00',
            $user->email_verified_at->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-11-19 12:30:00',
            $user->two_factor_confirmed_at->format('Y-m-d H:i:s')
        );

        $user->forceFill([
            'email_verified_at' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_書籍との一対多関連が定義されている(): void
    {
        $relation = (new User)->books();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(Book::class, $relation->getRelated());
        $this->assertSame('user_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getLocalKeyName());
    }

    public function test_レビューとの一対多関連が定義されている(): void
    {
        $relation = (new User)->reviews();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(Review::class, $relation->getRelated());
        $this->assertSame('user_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getLocalKeyName());
    }

    public function test_お気に入り書籍との多対多関連が定義されている(): void
    {
        $relation = (new User)->favoriteBooks();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(Book::class, $relation->getRelated());
        $this->assertSame('favorites', $relation->getTable());
        $this->assertSame('user_id', $relation->getForeignPivotKeyName());
        $this->assertSame('book_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());
    }

    public function test_いいねしたレビューとの多対多関連が定義されている(): void
    {
        $relation = (new User)->likedReviews();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(Review::class, $relation->getRelated());
        $this->assertSame('review_likes', $relation->getTable());
        $this->assertSame('user_id', $relation->getForeignPivotKeyName());
        $this->assertSame('review_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());
    }
}
