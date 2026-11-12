<?php

namespace App\Http\Requests;

use App\Models\Genre;
use Illuminate\Validation\Rule;

class UpdateGenreRequest extends StoreGenreRequest
{
    /**
     * 認証済み会員のジャンル更新を許可する。
     */
    public function authorize(): bool
    {
        $genre = $this->route('genre');

        return $genre instanceof Genre
            && ($this->user()?->can('update', $genre) ?? false);
    }

    /**
     * 更新対象自身を一意性判定から除外する。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = [
            'bail',
            'required',
            'string',
            'max:255',
            Rule::unique('genres', 'name')->ignore($this->route('genre')),
        ];

        return $rules;
    }

    /**
     * 入力不備時は対象ジャンルの編集フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('genres.edit', ['genre' => $this->route('genre')]);
    }
}
