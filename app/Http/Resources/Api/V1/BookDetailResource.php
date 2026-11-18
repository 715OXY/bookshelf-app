<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Book;
use Illuminate\Http\Request;

/** @mixin Book */
class BookDetailResource extends BookResource
{
    /**
     * 書籍の共通公開情報とレビュー情報を返す。
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'reviews' => ReviewResource::collection(
                $this->whenLoaded('reviews')
            ),
        ]);
    }
}
