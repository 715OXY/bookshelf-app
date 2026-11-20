<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;

class BookConstraintViolation
{
    /**
     * 書籍の保存時に発生した既知の制約違反を分類する。
     *
     * @return array<string, array<int, string>>|null
     */
    public function errors(QueryException $e): ?array
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $errorNumber = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? '');

        if ($sqlState !== '23000') {
            return null;
        }

        if (
            $errorNumber === 1062
            && preg_match(
                "~\bfor key '(?:books\.)?books_isbn_unique'\s*\z~",
                $message
            ) === 1
        ) {
            return [
                'isbn' => ['そのISBNは既に使用されています。'],
            ];
        }

        if ($errorNumber === 1452) {
            if (str_contains(
                $message,
                'CONSTRAINT `books_user_id_foreign` FOREIGN KEY'
            )) {
                return [
                    'user_id' => ['指定された登録者は存在しません。'],
                ];
            }

            if (str_contains(
                $message,
                'CONSTRAINT `book_genres_genre_id_foreign` FOREIGN KEY'
            )) {
                return [
                    'genres' => ['選択したジャンルは存在しません。'],
                ];
            }
        }

        return null;
    }
}
