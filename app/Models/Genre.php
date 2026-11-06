<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genre extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'name',
    ];

    /** @return BelongsToMany<Book> */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(
            Book::class,
            'book_genres',
            'genre_id',
            'book_id'
        )->withTimestamps();
    }
}
