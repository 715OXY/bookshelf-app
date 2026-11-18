<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Book */
class BookResource extends JsonResource
{
    /**
     * 書籍の共通公開情報を返す。
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'isbn' => $this->isbn,
            'published_date' => $this->published_date->format('Y-m-d'),
            'description' => $this->description,
            'image_url' => $this->image_url,
            'genres' => GenreResource::collection(
                $this->whenLoaded('genres')
            ),
            'average_rating' => $this->whenAggregated(
                'reviews',
                'rating',
                'avg',
                fn ($value): float => round((float) $value, 2),
                null
            ),
            'reviews_count' => $this->whenCounted(
                'reviews',
                fn ($value): int => (int) $value
            ),
        ];
    }
}
