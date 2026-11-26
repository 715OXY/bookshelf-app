<?php

namespace App\Services;

use App\Exceptions\IsbnLookupException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class GoogleBooksService
{
    private const ENDPOINT = 'https://www.googleapis.com/books/v1/volumes';

    private const DATE_ERROR = '出版日を確認できないため自動入力しませんでした。現在の入力内容は変更していません。書籍を確認し、手入力してください。';

    /**
     * 固定のGoogle Books窓口から取得し、応答順で最初の自動入力可能な完全一致候補を返す。
     * 候補ごとの不備は後続候補を確認し、使用可能な候補がなければエラーにする。
     *
     * @param  string  $isbn  FormRequestで検証済みの13桁ISBN
     * @return array{title: string, author: string, description: string, image_url: string, published_date: string}
     *
     * @throws IsbnLookupException 設定不足・外部障害・候補なし・自動入力できない情報の場合
     */
    public function lookup(string $isbn): array
    {
        $key = config('services.google_books.key');

        if (! is_string($key) || trim($key) === '') {
            throw new IsbnLookupException('現在、書籍情報を取得できません。時間をおいて再度お試しください。', 503);
        }

        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting()
                ->get(self::ENDPOINT, ['q' => 'isbn:'.$isbn, 'maxResults' => 10, 'key' => $key]);
        } catch (ConnectionException) {
            // 外部URL・APIキーを含む例外を連鎖させない。
            throw new IsbnLookupException('書籍情報の取得に接続できませんでした。時間をおいて再度お試しください。', 503);
        }

        if ($response->status() === 429) {
            throw new IsbnLookupException('現在、書籍情報を取得できません。時間をおいて再度お試しください。', 503);
        }

        if (! $response->ok()) {
            throw new IsbnLookupException('外部サービスから書籍情報を取得できませんでした。', 502);
        }

        try {
            $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IsbnLookupException('外部サービスの応答形式が正しくありません。', 502);
        }

        $data = $decoded instanceof \stdClass ? (array) $decoded : [];

        if (! isset($data['totalItems']) || ! is_int($data['totalItems']) || $data['totalItems'] < 0) {
            throw new IsbnLookupException('外部サービスの応答形式が正しくありません。', 502);
        }

        $items = array_key_exists('items', $data)
            ? $data['items'] : ($data['totalItems'] === 0 ? [] : null);

        if (! is_array($items) || ! array_is_list($items)) {
            throw new IsbnLookupException('外部サービスの応答形式が正しくありません。', 502);
        }

        $candidateError = null;

        foreach (array_slice($items, 0, 10) as $item) {
            if (! ($item instanceof \stdClass) || ! (($item->volumeInfo ?? null) instanceof \stdClass)) {
                throw new IsbnLookupException('外部サービスの応答形式が正しくありません。', 502);
            }

            $info = (array) $item->volumeInfo;

            if (! $this->matchesIsbn($info, $isbn)) {
                continue;
            }

            try {
                return $this->normalize($info);
            } catch (IsbnLookupException $e) {
                $candidateError ??= $e;
            }
        }

        throw $candidateError ?? new IsbnLookupException('取得した候補から指定ISBNと一致する書籍情報を確認できませんでした。', 404);
    }

    /**
     * 同じ候補のISBN_13が要求値と文字列で完全一致するか確認する。
     *
     * @param  array<string, mixed>  $info  候補の書誌情報
     */
    private function matchesIsbn(array $info, string $isbn): bool
    {
        $identifiers = $info['industryIdentifiers'] ?? null;

        if (! is_array($identifiers) || ! array_is_list($identifiers)) {
            return false;
        }

        foreach ($identifiers as $identifier) {
            $identifier = $identifier instanceof \stdClass ? (array) $identifier : null;
            if (is_array($identifier) && ($identifier['type'] ?? null) === 'ISBN_13'
                && ($identifier['identifier'] ?? null) === $isbn) {
                return true;
            }
        }

        return false;
    }

    /**
     * 単一候補を自動入力用に変換する。保存時の任意入力と異なり完全な出版日を要求する。
     * 任意項目の欠損・不正型は空文字とし、連結・変換後の保存上限を確認する。
     *
     * @param  array<string, mixed>  $info  完全一致候補の書誌情報
     * @return array<string, string> 出版日を含む自動入力用の5項目
     *
     * @throws IsbnLookupException 出版日不備または保存上限超過の場合
     */
    private function normalize(array $info): array
    {
        $date = $info['publishedDate'] ?? null;

        if (! is_string($date) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new IsbnLookupException(self::DATE_ERROR, 422);
        }

        $authors = $info['authors'] ?? null;
        $author = is_array($authors) && array_is_list($authors)
            && count(array_filter($authors, 'is_string')) === count($authors)
            ? implode(' / ', $authors) : '';
        $description = is_string($info['description'] ?? null)
            ? strip_tags(html_entity_decode($info['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $result = [
            'title' => is_string($info['title'] ?? null) ? $info['title'] : '',
            'author' => $author,
            'description' => $description,
            'image_url' => $this->imageUrl($info['imageLinks'] ?? null),
            'published_date' => $date,
        ];

        foreach (['title' => 255, 'author' => 255, 'description' => 10000, 'image_url' => 2048] as $field => $limit) {
            if (mb_strlen($result[$field]) > $limit) {
                throw new IsbnLookupException('取得情報が入力上限を超えるため自動入力しませんでした。現在の入力内容は変更していません。手入力してください。', 422);
            }
        }

        return $result;
    }

    /**
     * thumbnail、smallThumbnailの順で有効なHTTPS画像URLを選ぶ。取得不可なら空文字を返す。
     *
     * @param  mixed  $links  外部応答の画像リンク情報
     */
    private function imageUrl(mixed $links): string
    {
        if (! ($links instanceof \stdClass)) {
            return '';
        }

        $links = (array) $links;

        foreach (['thumbnail', 'smallThumbnail'] as $field) {
            $url = $links[$field] ?? null;

            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
                && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
                && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null) {
                return $url;
            }
        }

        return '';
    }
}
