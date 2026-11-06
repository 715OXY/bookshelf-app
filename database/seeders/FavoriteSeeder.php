<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class FavoriteSeeder extends Seeder
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

        foreach ($users as $userIndex => $user) {
            $favoriteCount = 3 + ($userIndex % 3);
            $bookIds = [];

            for ($index = 0; $index < $favoriteCount; $index++) {
                $bookIds[] = $books->get(
                    ($userIndex + $index) % $books->count()
                )->id;
            }

            $user->favoriteBooks()->syncWithoutDetaching($bookIds);
        }
    }
}
