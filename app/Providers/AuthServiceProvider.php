<?php

namespace App\Providers;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Policies\BookPolicy;
use App\Policies\GenrePolicy;
use App\Policies\ReviewPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * ModelとPolicyの対応。
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Book::class => BookPolicy::class,
        Genre::class => GenrePolicy::class,
        Review::class => ReviewPolicy::class,
    ];

    /**
     * 認証・認可に関するサービスを初期化する。
     */
    public function boot(): void
    {
        //
    }
}
