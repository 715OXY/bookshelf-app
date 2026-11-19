<?php

namespace App\Services\Api\V1;

use App\Models\Book;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BookWriteService
{
    /**
     * 書籍と選択ジャンルを同一トランザクションで登録する。
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int|string>  $genreIds
     */
    public function store(array $attributes, array $genreIds): Book
    {
        return $this->transaction(
            function () use ($attributes, $genreIds): Book {
                $book = Book::create($attributes);
                $book->genres()->sync($genreIds);

                return $book;
            }
        );
    }

    /**
     * 書籍と選択ジャンルを同一トランザクションで更新する。
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int|string>  $genreIds
     */
    public function update(
        Book $book,
        array $attributes,
        array $genreIds
    ): Book {
        return $this->transaction(
            function () use ($book, $attributes, $genreIds): Book {
                $book->update($attributes);
                $book->genres()->sync($genreIds);

                return $book;
            }
        );
    }

    /**
     * 書籍と関連データを同一トランザクションで削除する。
     */
    public function destroy(Book $book): void
    {
        $this->transaction(function () use ($book): void {
            $book->delete();
        });
    }

    /**
     * 書き込み処理を原子的に実行し、例外応答の直接公開を防ぐ。
     */
    private function transaction(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (HttpResponseException $e) {
            throw new RuntimeException(
                'API書き込み処理中に予期しない例外が発生しました。',
                0,
                $e
            );
        }
    }
}
