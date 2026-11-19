# マイルストーン10 モデル・DB関連検証記録

## 1. 対象と検証した版

- 記録日：2026-11-19
- 対象：MS10 基本機能のテスト
- 関連Issue：#54
- 基準コミット：b9a1f09
- 作業ブランチ：feature/issue-54-model-unit-db-tests
- 要件資料：Bookshelf要件シート 20261114版
- 関連資料：シート10、10-1、11、12
- 関連PM回答：H057、H058、H059
- 内部管理ID：T10-01、T10-02
- 検証時点：Issue #54のコミット前

Issue #53の基準検証結果を引き継ぎ、
モデル固有のUnit TestとDB関連Feature Testを補完した。

## 2. テスト設計方針

- モデル固有の振る舞いとDB上の振る舞いを分離する。
- Unit Testでは実際のDB接続を必要としない。
- Feature TestではMySQLの実際の制約を検証する。
- 既存テストを活用し、検証観点の不足を補完する。
- アプリケーションの機能・Migration・依存パッケージは変更しない。

### Unit Testの共通基盤

tests/Unit/Models/ModelTestCase.phpを作成した。

- PHPUnitのTestCaseを継承する。
- Eloquentのリレーション定義を取得できる環境を構成する。
- PDO接続を要求された場合は例外を送出する。
- パスワードのハッシュ化に必要な環境を構成する。
- テスト終了時に共通設定を復元する。

通常順序・ランダム順序でのUnit Testおよび
Feature Testとの統合実行により、
今回の検証範囲で他のテストへの影響がないことを確認した。

### Feature Testの安全対策

Issue #53で確認したテスト用DBの安全対策を継続する。

- APP_ENV：testing
- DB_CONNECTION：mysql
- DB_DATABASE：testing
- RefreshDatabaseによるテストデータの分離
- 実際の接続先データベース名の確認

開発用DBを対象とする初期化コマンドは実行しない。

## 3. モデルUnit Test

| モデル | テストクラス | ケース数 | Assertions |
|---|---|---:|---:|
| User | UserTest | 9 | 49 |
| Book | BookTest | 6 | 33 |
| Genre | GenreTest | 2 | 10 |
| Review | ReviewTest | 5 | 27 |
| 合計 | | 22 | 119 |

### User

- fillableと保護対象属性
- hiddenによる機密属性の除外
- 平文パスワードのハッシュ化
- ハッシュ化済みパスワードの再ハッシュ化防止
- 認証関連の日時属性のcast
- books、reviewsの一対多関連
- favoriteBooks、likedReviewsの多対多関連

### Book

- fillableと保護対象属性
- published_dateの日付cast
- userとの多対一関連
- reviewsとの一対多関連
- genres、favoritedByUsersの多対多関連

### Genre

- fillableと保護対象属性
- booksとの多対多関連

### Review

- fillableと保護対象属性
- ratingの整数cast
- user、bookとの多対一関連
- likedByUsersとの多対多関連

リレーション定義では関連モデル、外部キー、
中間テーブルおよびタイムスタンプ設定を検証した。

H059に関連する認証用属性の検証は、
応用機能である2FA自体の動作検証とは区別する。

## 4. DB関連Feature Test

既存のDatabaseConstraintsTestに14ケースを追加した。

| 検証分類 | 追加ケース数 |
|---|---:|
| 一意制約 | 2 |
| 参照中のユーザーの削除拒否 | 3 |
| レビュー評価の正常境界値 | 2 |
| 外部キー制約による登録拒否 | 7 |
| 合計 | 14 |

### 一意制約

- users.email
- genres.name

MySQLエラーコード1062、対象制約名、
重複登録失敗後の既存データ保持を確認した。

books.isbnの一意制約については、
既存のBookWriteConflictTestによる検証を活用する。

### 削除制限

| 参照元 | 制約 | 検証 |
|---|---|---|
| books.user_id | RESTRICT | 既存 |
| reviews.user_id | RESTRICT | 追加 |
| favorites.user_id | RESTRICT | 追加 |
| review_likes.user_id | RESTRICT | 追加 |
| book_genres.genre_id | RESTRICT | 既存 |

追加テストではMySQLエラーコード1451、
対象制約名、関連データの保持を確認した。

書籍・レビューの削除に伴うCASCADEについては、
既存のDB制約テストによる検証を継続する。

### レビュー評価の境界値

| 評価値 | 期待結果 | 検証 |
|---:|---|---|
| 0 | 登録拒否 | 既存 |
| 1 | 登録成功 | 追加 |
| 5 | 登録成功 | 追加 |
| 6 | 登録拒否 | 既存 |

正常境界値では整数型での取得と
コメントのNULL許容も確認した。

DBのNULL許容はレビュー編集フォームの
コメント必須化とは異なる責務として扱う。

### 外部キー制約

| 対象 | 不正参照の拒否 |
|---|---|
| books.user_id | 既存APIテスト |
| reviews.user_id | 追加 |
| reviews.book_id | 追加 |
| book_genres.book_id | 追加 |
| book_genres.genre_id | 既存APIテスト |
| favorites.user_id | 追加 |
| favorites.book_id | 追加 |
| review_likes.user_id | 追加 |
| review_likes.review_id | 追加 |

追加テストでは存在しない親レコードのIDを指定し、
MySQLエラーコード1452と対象制約名を確認した。

登録失敗後に不正なレコードが存在しないこと、
対象テーブルの既存データが保持されることも確認した。

### 既存検証の継続

- 中間テーブルの組み合わせの一意制約
- 書籍・レビュー削除時の連鎖削除
- レビュー更新時のいいね保持
- モデル間の双方向リレーション
- 同一ユーザーによる同一書籍への複数レビュー
- 自分の書籍のお気に入り登録
- 自分のレビューへのいいね
- Seederの初期データと依存順序

## 5. 統合検証結果

### Unit Test

| 検証 | 成功件数 | Assertions |
|---|---:|---:|
| 通常順序 | 23 | 120 |
| ランダム順序（Seed: 54） | 23 | 120 |
| DB接続テストとの統合 | 24 | 126 |

既存ExampleTestの1ケースを含む。

### DB関連Feature Test

| テストクラス | 成功件数 |
|---|---:|
| DatabaseConnectionTest | 1 |
| DatabaseConstraintsTest | 25 |
| DatabaseSeederTest | 1 |
| ModelRelationsTest | 3 |
| 合計 | 30 |

- Assertions：683
- 失敗・エラー：0

Unit TestとDB関連Feature Testの統合結果は、
53ケース・803 Assertionsの成功を確認した。

## 6. 全体回帰検証

| 項目 | Issue #53 | Issue #54 |
|---|---:|---:|
| Unit Test | 1 | 23 |
| Feature Test | 448 | 462 |
| 合計 | 449 | 485 |
| Assertions | 5046 | 5290 |
| 失敗・エラー | 0 | 0 |

追加ケース数：36
Assertionsの増加数：244

### 最終検証結果

- 成功件数：485
- Assertions：5290
- 失敗・エラー：0
- 非推奨警告：1
- 実行時間：36.346秒
- メモリ使用量：68.50 MB
- 計測条件：Xdebugによるカバレッジ計測込み

実行時間は個別の実測値であり、
性能改善・劣化を判定するための負荷試験ではない。

### SQL取得効率

| 検証対象 | 実測値 |
|---|---|
| 公開API書籍一覧 | 書籍1件・10件ともに3回 |
| 公開API書籍詳細 | レビュー1件・10件ともに6回 |
| 最大ページ番号指定 | 件数取得SQLを確認 |

既存のSQL取得効率に関する回帰テストは成功した。

## 7. コードカバレッジ

PHP 8.5.12、PHPUnit 10.5.66、
Xdebug 3.5.3を使用して計測した。

| 指標 | カバー数 / 総数 | カバレッジ |
|---|---:|---:|
| クラス | 45 / 56 | 80.36% |
| メソッド | 124 / 138 | 89.86% |
| 行 | 1002 / 1072 | 93.47% |

Issue #53の基準値を維持し、
基本機能の行カバレッジ目標60%超を達成した。

### モデル別カバレッジ

| モデル | Methods | Lines |
|---|---:|---:|
| User | 4 / 4 | 14 / 14 |
| Book | 4 / 4 | 14 / 14 |
| Genre | 1 / 1 | 6 / 6 |
| Review | 3 / 3 | 8 / 8 |
| 合計 | 12 / 12 | 42 / 42 |

4クラスともメソッド・行カバレッジ100%を維持した。

既存の実行済みコードに対する検証観点を補強したため、
今回の追加による全体カバレッジの数値変化はない。

分岐・パスカバレッジは計測していない。
カバレッジは正式要件への完全適合を保証しない。

応用機能を含むカバレッジ目標は、
応用機能実装後に改めて判定する。

## 8. コーディング規約・変更差分

### PHPファイルの変更範囲

- tests/Unit/Models/ModelTestCase.php
- tests/Unit/Models/UserTest.php
- tests/Unit/Models/BookTest.php
- tests/Unit/Models/GenreTest.php
- tests/Unit/Models/ReviewTest.php
- tests/Feature/DatabaseConstraintsTest.php

Pintのphp_unit_method_casing違反が
2ファイル・4メソッドで検出された。

メソッド名のみ修正し、
テストロジックやPint設定は変更していない。

### 最終確認結果

- Pint：対象6ファイルすべてPASS
- Git差分チェック：対象6ファイルすべてPASS
- 修正後の統合テスト：53ケース成功
- 修正後の全体回帰テスト：485ケース成功

既存の全体Pint違反については後続で管理する。

## 9. 既知の問題・残存課題

### Sanctumの非推奨警告

- パッケージ：laravel/sanctum v3.3.3
- 該当ファイル：vendor/laravel/sanctum/src/HasApiTokens.php
- 該当メソッド：createToken
- 該当箇所：46行目
- 内容：expiresAt引数の暗黙的なnullable型指定
- 確認環境：PHP 8.5.12
- 最終全体テストでの発生件数：1

Userモデルの読み込みに伴って発生する
依存パッケージ由来の非推奨警告である。

今回のテストに失敗・エラーは発生していないが、
互換性上の問題として未解消のまま管理する。

今回のIssueではvendorの直接修正、
依存パッケージの更新、警告の抑制は行わない。

応用機能のSanctum認証を実装する際に、
互換性・依存関係・対応方針を再確認する。

### 後続への引継ぎ

- シート8-1のコーチ指摘の反映
- Web機能・公開APIの不足検証
- カバレッジの最終再計測
- リポジトリ全体のPint検査
- 個別テストケースの正式要件との照合
- 要件シートへの検証結果の転記
- 新規取得環境でのREADME再現検証

実際の並行競合試験、大規模負荷試験、
開発用DBの検証前後のハッシュ比較は実施していない。

## 10. 再現手順

Laravel Sailのコンテナが稼働している状態で実行する。

### モデルUnit Test

    ./vendor/bin/sail php vendor/bin/phpunit \
        tests/Unit/Models --display-deprecations

### DB関連Feature Test

    ./vendor/bin/sail php vendor/bin/phpunit \
        --filter 'DatabaseConnectionTest|DatabaseConstraintsTest|DatabaseSeederTest|ModelRelationsTest'

### 全体回帰検証・カバレッジ計測

    docker compose exec -T -u sail \
        -e XDEBUG_MODE=coverage \
        laravel.test \
        php -d pcov.enabled=0 vendor/bin/phpunit \
        --coverage-text --display-deprecations

### 対象PHPファイルのPint検査

    ./vendor/bin/sail php vendor/bin/pint --test \
        tests/Unit/Models \
        tests/Feature/DatabaseConstraintsTest.php

## 11. 関連資料

- [MS10テスト環境・基準検証記録](ms10-test-environment.md)
- [MS10基本機能テスト要件対応表](ms10-test-matrix.md)
- [認証仕様・検証記録](authentication.md)
- [MS6検証記録](ms6-verification.md)
- [MS8統合検証記録](ms8-verification.md)
- [MS9統合検証記録](ms9-verification.md)
- [公開API詳細設計](api-design.md)
