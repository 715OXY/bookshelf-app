<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'author' => fake()->name(),
            'isbn' => fake()->unique()->isbn13(),
            'published_date' => fake()->date('Y-m-d'),
            'description' => fake()->paragraph(),
            'image_url' => 'https://placehold.co/200x300',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Book $book): void {
            $book->genres()->attach(Genre::factory()->create()->id);
        });
    }
}
