# 公開API詳細設計

## 対象・根拠

- MS9：公開API
- Issue #43〜#46
- 基準コミット：848cec6
- Bookshelf要件シート20261114版
- 関連シート：2・3・7・8・10・11・12・13
- PM回答：H007・H058
- 既存の書籍管理機能・FormRequest・Model・Migration

## 設計の状態

本文の詳細仕様は開発側の設計案であり、
PMの明示回答による承認済み仕様とは区別する。

2026年11月18日にIssue #43のFormRequest実装と
入力検証・既存機能の回帰確認が完了した。

2026年11月19日時点でIssue #44の正式APIルート、
一覧・詳細取得Controller・Resourceの実装と検証が完了している。
2026年11月19日にIssue #45の登録・更新・削除処理、
共通JSON例外応答および実装範囲の検証が完了した。
正式API全体の最終検証・提出資料との照合はIssue #46で行う。

## 基本方針

- 基本APIは認証なしのCRUDとする。
- Web用の認証・認可・リダイレクトを変更しない。
- API専用のFormRequest・Controller・Resourceを使用する。
- 既存のModel・DB構造・リレーションを利用する。
- APIの例外応答はJSONに統一する。
- 認証なしの書込APIは模擬案件の基本段階に限定し、
  実運用向けの認証済みAPIと混同しない。

## エンドポイント

| ID | Method | URI | 成功時 |
| --- | --- | --- | --- |
| AP01 | GET | /api/v1/books | 200 |
| AP02 | GET | /api/v1/books/{book} | 200 |
| AP03 | POST | /api/v1/books | 201 |
| AP04 | PUT | /api/v1/books/{book} | 200 |
| AP05 | DELETE | /api/v1/books/{book} | 204 |

基本段階では全エンドポイントの認証を不要とする。
PATCHは今回の対象外とする。

## 一覧取得の入力仕様

| 項目 | 入力条件 | 既定値 |
| --- | --- | --- |
| keyword | 任意・文字列・255文字以下 | なし |
| genre_id | 任意・整数・存在するジャンルID | なし |
| page | 任意・整数・1〜2147483647 | 1 |
| per_page | 任意・整数・1〜100 | 20 |

- キーワードはタイトル・著者・ISBNの部分一致。
- キーワードの各検索対象はOR条件でグループ化する。
- ジャンル条件とはANDで組み合わせる。
- LIKE特殊文字（%、_、!）は通常の文字として検索する。
- ESCAPE句とパラメータバインディングを使用する。
- 並び順はcreated_at降順、同日時はid降順。
- ページネーションリンクに検索条件を引き継ぐ。
- 最終ページを超える要求は空の一覧を返す。
- 不正な入力値は422を返す。
- ページ番号の上限は2147483647とする。
- 検索条件に一致する総件数を事前に取得する。
- 有効なページ範囲内の場合のみforPageで書籍を取得する。
- 範囲超過時は書籍取得SQLを発行せず空配列を返す。
- LengthAwarePaginatorとAPI Resourceを使用する。
- 範囲超過時も要求されたページ番号をcurrent_pageに保持する。
- ページリンクはLaravel標準の生成方式を維持する。
- ページリンクには検証済みの検索条件とページ件数を引き継ぐ。
- 巨大OFFSET対策はIssue #44で実装・検証済み。

## 登録・更新の入力仕様

| 項目 | 登録 | 更新 | 入力条件 |
| --- | --- | --- | --- |
| user_id | 必須 | 指定不可 | 整数・存在するユーザーID |
| title | 必須 | 必須 | 文字列・255文字以下 |
| author | 必須 | 必須 | 文字列・255文字以下 |
| isbn | 必須 | 必須 | 文字列・半角数字13桁・一意 |
| published_date | 必須 | 必須 | YYYY-MM-DD形式の有効な日付 |
| description | 任意 | 任意 | nullable・文字列・10000文字以下 |
| image_url | 任意 | 任意 | nullable・URL形式・2048文字以下 |
| genres | 必須 | 必須 | 配列・1件以上 |
| genres.* | 必須 | 必須 | 整数・重複不可・存在確認 |

- 更新時のISBN一意性判定から対象書籍自身を除外する。
- 更新時はuser_idの指定自体を拒否する。
- user_idにはmissingルールを使用し、NULL・空文字・空配列の指定も拒否する。
- PUTは書籍情報全体の更新として扱う。
- 任意項目を省略した場合はNULLとして保存する。
- ジャンルの紐付けは指定された内容に同期する。
- 日本語のバリデーションメッセージを定義する。
- 検証済みデータのみを保存処理に使用する。

## JSONレスポンス仕様

### 一覧

Laravel標準のページネーション付きResourceを使用する。

- data：書籍の配列
- links：ページネーションリンク
- meta：現在ページ・総件数等のページ情報

書籍の基本項目：

- id
- title
- author
- isbn
- published_date
- description
- image_url
- genres（id・name）
- average_rating
- reviews_count

レビューが存在しない場合は
average_ratingをnull、reviews_countを0とする。
平均評価は数値として小数第2位まで丸める。

### 詳細・登録成功・更新成功

dataに書籍情報を格納する。
一覧の基本項目に加え、reviewsを含める。

レビューの項目：

- id
- user_name
- rating
- comment
- created_at

レビューが存在しない場合は空配列とする。
出版日はYYYY-MM-DD形式とする。
投稿日時はISO 8601形式とする。
不要なユーザー情報や内部情報は公開しない。

### 削除成功

HTTP 204とし、レスポンスボディを返さない。

## エラー応答

| Status | 用途 |
| --- | --- |
| 404 | 対象書籍不存在・未定義API |
| 405 | 許可されていないHTTPメソッド |
| 422 | 入力検証エラー・分類対象のDB制約違反 |
| 429 | レート制限超過 |
| 500 | サーバー内部エラー |

- 共通のmessage項目を日本語で返す。
- 入力検証および分類対象のDB制約違反による422では、errorsに項目別のメッセージ配列を格納する。
- 422の共通messageは「入力内容に誤りがあります。」とする。
- Acceptヘッダー省略時もJSONで返す。
- 内部SQL・接続情報・スタックトレースを公開しない。
- 既存Webのエラー応答を変更しない。
- HTTP標準のエラー用ヘッダーを維持する。
- 詳細取得の404では「指定された書籍は存在しません。」を返す。
- 一覧取得の422および詳細取得の404はIssue #44で検証済み。
- 405・429・500を含む共通JSON例外応答はIssue #45で実装・検証済み。
- 共通例外処理の対象はapi/v1およびその配下とする。
- その他のAPIおよびWebの例外処理は既存方針を維持する。
- 405ではAllow、429ではRetry-After等の必要なヘッダーを維持する。
- デバッグ設定の有無によらず予期しない内部例外の詳細を公開しない。

### 共通エラーメッセージ

| Status | message |
| --- | --- |
| 404（書籍不存在） | 指定された書籍は存在しません。 |
| 404（未定義API） | 指定されたAPIは存在しません。 |
| 405 | 許可されていないHTTPメソッドです。 |
| 422 | 入力内容に誤りがあります。 |
| 429 | リクエスト回数が上限を超えました。 |
| 500 | サーバー内部でエラーが発生しました。 |

## データ整合性・取得効率

- 書籍と関連データの変更はトランザクションで処理する。
- 削除時は既存の外部キーによる連動削除方針を維持する。
- レビュー・いいね・お気に入り・ジャンル紐付けを処理する。
- 無関係な書籍・ユーザー・ジャンルは保持する。
- Eager Loadingと関連集計によってN+1を回避する。
- 既存の60リクエスト/分のレート制限を維持する。
- 登録・更新時は書籍本体とジャンル同期を同一トランザクションで処理する。
- 更新時は登録者・レビュー・お気に入り・レビューいいねを保持する。
- 削除時は既存の外部キー制約による連鎖削除を利用する。
- トランザクション内の処理失敗時は部分変更をロールバックする。
- コミット後のResource取得・応答生成障害はロールバック保証範囲外とする。

## FormRequestの実装構成

配置先：app/Http/Requests/Api/V1/

| クラス | 責務 |
| --- | --- |
| ApiFormRequest | 共通認可・422 JSONエラー応答 |
| IndexBookRequest | 一覧取得の入力検証 |
| StoreBookRequest | 書籍登録の入力検証 |
| UpdateBookRequest | 書籍更新の入力検証 |

- ApiFormRequestは抽象基底クラスとする。
- IndexBookRequestとStoreBookRequestはApiFormRequestを継承する。
- UpdateBookRequestはStoreBookRequestを継承する。
- 更新時はuser_idとISBNの入力ルールを変更する。
- ISBNの自身除外にはルートモデルバインディングを利用する。
- 既存Web用FormRequestは変更しない。
- ApiFormRequestはApiValidationExceptionを使用して422 JSON応答を生成する。

## Controller・Resourceの実装構成

| ファイル | 責務 |
| --- | --- |
| routes/api.php | v1公開APIの5ルート定義 |
| app/Http/Controllers/Api/V1/BookController.php | 書籍CRUD・Resourceによる応答生成 |
| app/Http/Resources/Api/V1/BookResource.php | 書籍の共通公開項目 |
| app/Http/Resources/Api/V1/BookDetailResource.php | 共通公開項目とレビュー情報 |
| app/Http/Resources/Api/V1/GenreResource.php | ジャンルの公開項目 |
| app/Http/Resources/Api/V1/ReviewResource.php | レビューと投稿者の公開項目 |

- 一覧取得にはIndexBookRequestを使用する。
- 詳細取得にはルートモデルバインディングを使用する。
- BookDetailResourceはBookResourceを継承する。
- 一覧ではwith・withAvg・withCountを使用する。
- 詳細ではload・loadAvg・loadCountを使用する。
- Resourceでは読み込み済みの関連情報と集計値を使用する。
- 既存のModel・Migration・Web用Controllerは変更しない。
- POST・PUT・DELETEの暫定501応答はIssue #45で正式処理へ置換した。
- 登録・更新・削除の永続化処理はBookWriteServiceへ委譲する。
- 登録・更新の成功応答にはBookDetailResourceを再利用する。
- 対象書籍不存在などの例外はHandlerとApiExceptionRendererで処理する。
- 既存のauth:sanctum付きapi/userルートは維持する。

## Service・例外処理の実装構成

| ファイル | 責務 |
| --- | --- |
| app/Services/Api/V1/BookWriteService.php | 書籍の永続化・ジャンル同期・トランザクション |
| app/Exceptions/Handler.php | APIとWebの例外処理の分離 |
| app/Exceptions/ApiExceptionRenderer.php | API共通JSON例外応答 |
| app/Exceptions/ApiValidationException.php | 入力検証用の422 JSON応答 |
| app/Exceptions/BookConstraintViolation.php | 特定のDB制約違反の分類 |

### DB制約違反への対応

入力検証とDB書き込みの間にデータが変化する可能性を考慮し、
以下の既存DB制約に対応するQueryExceptionを422へ変換する。

| DB制約 | errorsの対象項目 |
| --- | --- |
| books_isbn_unique | isbn |
| books_user_id_foreign | user_id |
| book_genres_genre_id_foreign | genres |

- MySQLのSQLSTATE・エラー番号・制約名を用いて分類する。
- 分類対象外のQueryExceptionは共通500応答とする。
- DB例外の原文・SQL・内部接続情報は公開しない。
- 既存MigrationおよびDB制約は変更しない。

### 例外応答の安全性

- 正規の入力検証にはApiValidationExceptionを使用する。
- ApiExceptionRendererで任意のHttpResponseExceptionを直接返却しない。
- BookWriteService内のHttpResponseExceptionはRuntimeExceptionへ変換する。
- 元の例外はpreviousとして保持し、クライアントへ公開しない。
- Laravelのvendor配下は変更しない。
- Service外で発生する全HttpResponseExceptionを包括的に防ぐ仕組みではない。

## 実装分担

- #43：API詳細設計・FormRequest
- #44：ルート・一覧詳細Controller・Resource
- #45：登録更新削除・JSON例外応答
- #46：統合検証・Web回帰確認・資料更新

## 検証方針

- 未認証での利用
- 入力条件・境界値・日本語メッセージ
- ISBN重複および自身除外
- 登録者IDの妥当性・更新時の指定拒否
- 検索・絞り込み・ページネーション
- JSON構造・HTTPステータス
- Acceptヘッダー省略時の応答
- 関連削除・失敗時のDB不変
- N+1・既存Web機能の回帰

MS8の検証記録にある284件・2937アサーション成功を
回帰確認の参考基準とする。
MS9のテスト結果はIssueごとに記録する。

## Issue #43の検証記録

検証日：2026年11月18日

テスト配置先：tests/Feature/Api/V1/

| ファイル | テスト件数 |
| --- | ---: |
| BookValidationTestCase.php | 共通基底クラス |
| IndexBookValidationTest.php | 14 |
| StoreBookValidationTest.php | 20 |
| UpdateBookValidationTest.php | 6 |
| 合計 | 40 |

正式APIの実装に先立ち、Feature Test内に検証専用ルートを
定義してFormRequestの動作を独立して確認した。

検証専用ルートは検証済みデータを返すだけとし、
書籍の登録・更新・削除処理は実行しない。

| 検証項目 | 結果 |
| --- | --- |
| API入力検証テスト | 40件・161 assertions成功 |
| 全体回帰テスト | 324件・3098 assertions成功 |
| PHP構文チェック | 8ファイル成功 |
| Pint | 8ファイル成功 |
| PHPファイル行数 | 全8ファイル200行以内 |
| 既存追跡済みファイルの変更 | なし |

テストコードは責務別に分割し、
分割前後でテスト40件・161 assertionsの成功を維持した。

## Issue #43からの引継ぎ事項

- #44：正式ルート・Controller・Resourceへの接続。
- #44：検索・絞り込み・既定値・ページネーションの実装と検証。
- #44：関連データ取得・集計・N+1対策の検証。
- #45：CRUD・トランザクション・関連削除の実装と検証。
- #45：PUTの任意項目省略時のNULL更新。
- #45：正式APIのJSON例外応答とHTTPステータスの検証。
- #46：正式APIの統合検証・Web回帰確認・資料更新。
- PMへの設計内容の事後確認と要件シートへの反映。

今回の入力検証テストの成功を、
正式API全体の動作検証完了とは扱わない。

## Issue #44の実装・検証記録

検証日：2026年11月19日

作業ブランチ：feature/issue-44-api-read

作業開始時の基準コミット：99a58f9

### 実装範囲

- AP01：書籍一覧取得API。
- AP02：書籍詳細取得API。
- AP03〜AP05：ルート定義と暫定501応答。
- API専用Controllerおよび4種類のResource。
- 正式APIを対象としたFeature Test。

### 自動テスト

テスト配置先：tests/Feature/Api/V1/

| ファイル | テスト件数 | assertions |
| --- | ---: | ---: |
| BookReadTestCase.php | 共通基底クラス | - |
| BookIndexTest.php | 7 | 71 |
| BookIndexSearchTest.php | 9 | 71 |
| BookIndexPaginationTest.php | 10 | 100 |
| BookShowTest.php | 8 | 75 |
| BookQueryEfficiencyTest.php | 3 | 35 |
| 合計 | 37 | 352 |

- RefreshDatabaseによる再現可能なテストを実装した。
- DB初期化前にtesting環境・ドライバー・接続先DBを確認する。
- 既存Factoryを再利用し、必要に応じてジャンル紐付けを同期する。
- Issue #43の入力検証専用テストは変更していない。

### 検証結果

| 検証項目 | 結果 |
| --- | --- |
| 一覧取得APIの簡易HTTP検証 | 15件成功 |
| 詳細取得APIの簡易HTTP検証 | 5件成功 |
| 一覧・既存APIテスト一括実行 | 66件・403 assertions成功 |
| 新規自動テスト | 37件・352 assertions成功 |
| 全体回帰テスト | 361件・3450 assertions成功 |
| 全体回帰テスト実行時間 | 16.16秒 |
| PHP構文チェック | 16ファイル成功 |
| Pint | 20ファイル成功 |
| PHPファイル行数 | 確認対象16ファイルが200行以内 |
| 既存Web機能への回帰影響 | テスト範囲内で検出されず |

### SQL取得効率の実測値

| 検証条件 | SELECT文発行回数 |
| --- | ---: |
| 一覧取得：書籍1件 | 3 |
| 一覧取得：書籍10件 | 3 |
| 詳細取得：レビュー1件 | 6 |
| 詳細取得：レビュー10件 | 6 |
| 最大ページ番号・100件指定 | 1 |

- 書籍数・レビュー数の増加によるSQL発行回数の増加はない。
- 最大ページ番号2147483647の要求では総件数取得のみを実行した。
- OFFSET句およびLIMIT句を含むSELECT文は発行されなかった。
- Resource単体のJSON変換時の追加SQL発行回数は0回だった。
- 上記は指定した検証条件における実測値であり、
  大規模データに対する負荷試験結果ではない。

## Issue #44の不具合修正記録

### レビュー0件の場合にaverage_ratingが欠落する

- 発見工程：STEP 7-2-2。
- 現象：レビューが存在しない場合にaverage_ratingの項目が欠落した。
- 期待値：average_ratingはnull、reviews_countは0。
- 原因：Laravel 10のwhenAggregated内部でissetを使用しており、
  NULL集計値に対して既定のMissingValueが返された。
- 修正：BookResourceのwhenAggregatedの第5引数にnullを指定した。
- 影響範囲：BookResourceおよび継承先のBookDetailResource。
- 再検証：修正前5件成功・2件失敗、修正後7件・71 assertions成功。
- 詳細取得でもレビュー0件時のnull・0・空配列を検証済み。
- Laravel内部ファイルおよびテスト期待値は変更していない。

## Issue #44からの引継ぎ事項

- #45：書籍登録・更新・削除処理の実装。
- #45：トランザクション・ジャンル同期・関連削除の検証。
- #45：登録・更新成功時のBookDetailResourceの再利用。
- #45：PUTの任意項目省略時のNULL更新。
- #45：405・429・500を含む共通JSON例外応答。
- #45：ルート側の404応答と共通例外処理の整合性確認。
- #45：HTTP標準のAllow・Retry-After等のヘッダー維持。
- #46：正式API全体の統合検証・Web回帰確認・資料更新。
- MS10：PHPとLaravel Sanctumの互換性警告の再確認。
- PMへの設計内容の事後確認と要件シートへの反映。

PATCH要求に対する405応答では、Acceptヘッダー省略時に
HTML形式のデバッグ用エラー画面が返されることを確認した。
共通JSON例外応答の実装時に解消する。

Resource単体検証時にSanctumのcreateTokenメソッドについて
暗黙的nullable型宣言のDEPRECATED警告が発生した。
今回のJSON変換への影響は確認されていない。
vendor配下の直接修正は行わず、互換性確認を後続へ引き継ぐ。

Issue #44の検証成功を、書込APIを含む公開API全体の
実装・検証完了とは扱わない。

## Issue #45の実装・検証記録

検証日：2026年11月19日

作業ブランチ：feature/issue-45-api-write

作業開始時の基準コミット：0c56aa5

### 実装範囲

- AP03：書籍登録API。
- AP04：書籍更新API。
- AP05：書籍削除API。
- トランザクション・ジャンル同期・関連削除。
- API共通JSON例外応答とDB制約違反の分類。
- 入力検証専用例外と書き込み処理の安全性補強。
- API書き込み・例外処理・統合検証用のFeature Test。

### 自動テスト

テスト配置先：tests/Feature/Api/V1/

| ファイル | テスト件数 | assertions |
| --- | ---: | ---: |
| BookWriteTestCase.php | 共通基底クラス | - |
| BookStoreTest.php | 5 | 57 |
| BookUpdateTest.php | 5 | 63 |
| BookDestroyTest.php | 3 | 33 |
| BookWriteConflictTest.php | 5 | 40 |
| ApiExceptionTest.php | 8 | 81 |
| ApiRateLimitTest.php | 3 | 458 |
| BookWriteTransactionTest.php | 3 | 42 |
| ApiExceptionSafetyTest.php | 4 | 51 |
| BookApiIntegrationTest.php | 1 | 50 |
| 合計 | 37 | 875 |

- RefreshDatabaseを使用する。
- DB初期化前にtesting環境・MySQL・テスト専用DBを確認する。
- 実際のDB制約違反およびSQL実行直後の障害をテスト内で再現する。
- 関連7テーブルの状態を比較し、部分変更が残らないことを確認する。
- レート制限超過・接続元の分離・制限時間経過後の復帰を確認する。
- 未認証での書籍登録から削除までのライフサイクルを確認する。

### 検証結果

| 検証項目 | 結果 |
| --- | --- |
| 新規自動テスト | 37件・875 assertions成功 |
| 公開API一括テスト（統合テスト追加前） | 113件・1338 assertions成功 |
| ライフサイクル統合テスト | 1件・50 assertions成功 |
| 全体回帰テスト | 398件・4325 assertions成功 |
| 全体回帰テスト実行時間 | 19.50秒 |
| 参照系・異常系HTTP疎通確認 | 5件成功 |
| 書き込み系HTTP疎通確認 | 8件成功 |
| HTTP疎通確認合計 | 13件成功 |
| PHP構文チェック | 18ファイル成功 |
| Pint | 18ファイル成功 |
| PHPファイル行数 | 全18ファイル200行以内 |
| 最大PHPファイル行数 | 175行 |
| Git差分チェック | 全18ファイル合格 |
| 既存Web機能への回帰影響 | テスト範囲内で検出されず |

公開API関連テストは統合テストを含めて114件となった。

### SQL取得効率の回帰確認

| 検証条件 | SELECT文発行回数 |
| --- | ---: |
| 一覧取得：書籍1件 | 3 |
| 一覧取得：書籍10件 | 3 |
| 詳細取得：レビュー1件 | 6 |
| 詳細取得：レビュー10件 | 6 |
| 最大ページ番号指定 | 1 |

Issue #44の検証結果からクエリ数の増加は確認されなかった。
実データを用いた負荷試験ではない。

### HTTP疎通確認

検証環境：Sail・PHP 8.5.12・MySQL 8.4。

接続先：http://localhost/api/v1/books

| 検証内容 | HTTP Status |
| --- | ---: |
| 書籍一覧取得 | 200 |
| 不存在書籍の詳細取得 | 404 |
| 未定義API | 404 |
| 未許可メソッド | 405 |
| 不正な一覧取得条件 | 422 |
| 検証専用書籍の登録 | 201 |
| 登録後の詳細取得 | 200 |
| 不正な書籍更新 | 422 |
| 正常な書籍更新 | 200 |
| 更新後の詳細取得 | 200 |
| 更新後の一覧取得 | 200 |
| 検証専用書籍の削除 | 204 |
| 削除後の詳細取得 | 404 |

- 認証情報およびAcceptヘッダーを明示せずに検証した。
- 405応答でAllowヘッダーが維持されることを確認した。
- レート制限関連ヘッダーを確認した。
- 204応答の本文が空であることを確認した。
- 開発用DBの既存データを保護し、検証専用書籍のみを操作した。

### 開発用DBの整合性確認

| テーブル | 検証前 | 検証後 |
| --- | ---: | ---: |
| users | 5 | 5 |
| books | 11 | 11 |
| genres | 10 | 10 |
| reviews | 32 | 32 |
| book_genres | 16 | 16 |
| favorites | 19 | 19 |
| review_likes | 48 | 48 |
| 合計 | 141 | 141 |

- 全7テーブルについてレコード件数とSHA-256ハッシュ値が一致した。
- 検証専用書籍は削除済み。
- AUTO_INCREMENTの進行は許容する。
- 個人情報等のレコード内容は検証結果に出力していない。

## Issue #45の不具合修正記録

### HttpResponseExceptionが共通JSON例外処理を迂回する

- 発見工程：STEP 7-3-3。
- 現象：意図的に発生させたHttpResponseExceptionのHTML・JSON・
  リダイレクト応答が直接返された。
- 期待値：安全な500 JSON応答に変換される。
- 原因：LaravelのRoute::run()がHttpResponseExceptionを直接捕捉し、
  Handlerを経由せずgetResponse()を返す。
- 修正：BookWriteServiceでHttpResponseExceptionを捕捉し、
  RuntimeExceptionへ変換して再送出する。
- 影響範囲：BookWriteServiceの共通トランザクション処理。
- 再検証：修正前1件成功・3件失敗、
  修正後4件・51 assertions成功。
- トランザクション・DB制約違反の回帰テスト8件・82 assertions成功。
- vendor配下およびテストの期待値は変更していない。
- Service外の全例外経路を包括的に保護するものではない。

## Issue #45からの引継ぎ事項

- #46：基本API全体の最終統合検証・既存Web機能の回帰確認。
- #46：README・要件シート・API詳細仕様・テストケースとの照合。
- #46：HTTPエラー応答および実装仕様の最終確認。
- 実際の並行リクエストによる競合試験は未実施。
- DB制約違反テストはテスト内で競合相当の状態を再現したものである。
- 大規模データおよび並行リクエストによる負荷試験は未実施。
- コミット後の応答生成障害はロールバック保証範囲外。
- MS10：Sanctum認証・所有者認可の追加と互換性確認。
- PMへの設計内容の事後確認と要件シートへの反映。

Issue #45の実装・検証成功は、MS9の全工程完了や
PMによる明示承認を意味するものではない。

## PM確認事項

- ページ件数の既定値20件・上限100件
- ページ番号の上限2147483647
- キーワード検索の対象項目・一致条件
- LIKE特殊文字を通常の文字として扱う検索仕様
- 並び順と同一作成日時の場合のID降順
- ページ範囲超過時の空配列・ページ情報・ページリンクの扱い
- 登録者IDの指定方法と更新不可の扱い
- PUTの任意項目省略時のNULL更新
- JSONレスポンスの項目・構造・平均評価の精度
- HTTPステータス・エラー応答形式
- 特定のDB制約違反を422として扱う方針
- Serviceによる永続化処理の分離と例外応答の安全性補強
- トランザクションの保証範囲

## 応用機能への引継ぎ

- POST・PUT・DELETEにSanctum認証を追加する。
- 登録者IDは認証済みユーザーから取得する方式へ変更する。
- 更新・削除にBookPolicyによる所有者認可を追加する。
- 401・403のJSON応答を設計・検証する。
- 現行のApiFormRequest・BookWriteService・ApiExceptionRendererを再評価する。
- 基本APIの未認証書き込みから認証・所有者認可付きの仕様へ移行する。
- SanctumのPHP互換性警告を再確認する。

## 資料反映先

- シート2：設計タスク・ヒアリング記録
- シート8：APIバリデーション
- シート10：APIテストケース
- シート13：API入出力詳細仕様

Excel原本への反映とPM承認は別途管理する。
