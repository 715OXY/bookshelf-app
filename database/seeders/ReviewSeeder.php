<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::orderBy('id')->get();
        $books = Book::orderBy('id')->get();

        if ($users->count() !== 5 || $books->count() !== 11) {
            throw new RuntimeException(
                '指定の5ユーザー・11冊を投入してから実行してください。'
            );
        }

        $comments = [
            3 => '内容を理解するきっかけになりました。気になる部分をもう一度読みたいです。',
            4 => '読み進めるほど理解が深まりました。印象に残る一冊です。',
            5 => 'とても読み応えがありました。ぜひ繰り返し読みたい一冊です。',
        ];

        foreach ($books as $bookIndex => $book) {
            $reviewCount = $bookIndex === 10 ? 2 : 3;

            for ($index = 0; $index < $reviewCount; $index++) {
                $user = $users->get(
                    ($bookIndex + $index) % $users->count()
                );
                $rating = 3 + (($bookIndex + $index) % 3);

                Review::create([
                    'user_id' => $user->id,
                    'book_id' => $book->id,
                    'rating' => $rating,
                    'comment' => "『{$book->title}』を読みました。{$comments[$rating]}",
                ]);
            }
        }
    }
}
