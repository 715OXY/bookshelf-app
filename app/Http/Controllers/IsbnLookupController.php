<?php

namespace App\Http\Controllers;

use App\Exceptions\IsbnLookupException;
use App\Http\Requests\LookupIsbnRequest;
use App\Models\Book;
use App\Services\GoogleBooksService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class IsbnLookupController extends Controller
{
    /**
     * 登録権限を確認し、検証済みのパスISBNに対応する自動入力情報をJSONで返す。
     *
     * @param  LookupIsbnRequest  $request  パスISBNを検証したリクエスト
     * @param  GoogleBooksService  $service  外部取得・候補選択・変換を担うサービス
     * @return JsonResponse 自動入力用の文字列5項目
     *
     * @throws AuthorizationException 登録権限がない場合
     * @throws IsbnLookupException 取得・変換に失敗した場合。窓口専用Handlerでerrorへ変換する
     */
    public function __invoke(LookupIsbnRequest $request, GoogleBooksService $service): JsonResponse
    {
        $this->authorize('create', Book::class);

        return response()->json($service->lookup($request->validated('isbn')));
    }
}
