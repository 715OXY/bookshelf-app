<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Review extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'user_id',
        'book_id',
        'rating',
        'comment',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rating' => 'integer',
    ];

    /** @return BelongsTo<User, Review> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Book, Review> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return BelongsToMany<User> */
    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'review_likes',
            'review_id',
            'user_id'
        )->withTimestamps();
    }
}
