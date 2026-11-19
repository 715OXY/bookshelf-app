# マイルストーン10 テスト環境・基準検証記録

## 1. 対象と検証した版

- 記録日：2026-11-19
- 対象：MS10 基本機能のテスト
- 関連Issue：#53
- 基準コミット：f6cbb20
- 作業ブランチ：feature/issue-53-test-environment
- 要件資料：Bookshelf要件シート 20261114版
- 関連資料：シート10、シート10-1
- 基本機能のカバレッジ目標：60%超
- 応用機能を含むカバレッジ目標：80%以上

## 2. テスト実行環境

| 項目 | 確認結果 |
|---|---|
| 開発環境 | WSL Ubuntu 24.04 |
| コンテナ環境 | Laravel Sail |
| PHP | 8.5.12 |
| Laravel | 10.50.4 |
| MySQL | 8.4 |
| PHPUnit | 10.5.66 |
| Xdebug | 3.5.3 |
| PCOV | 1.0.12 |
| 通常時のXDEBUG_MODE | off |
| 設定キャッシュ | なし |
| テストスイート | Unit、Feature |
| カバレッジ計測対象 | appディレクトリ |

PHPは緊急セキュリティ修正案内に対応した
バージョンであることを確認した。

## 3. テスト用DBの安全対策

phpunit.xmlで以下の環境変数を強制指定する。

| 環境変数 | 設定値 |
|---|---|
| APP_ENV | testing |
| DB_CONNECTION | mysql |
| DB_HOST | mysql |
| DB_PORT | 3306 |
| DB_DATABASE | testing |

### 共通安全対策

- CreatesApplicationでtesting環境であることを確認する。
- MySQLへの接続とSELECT DATABASE()の結果を確認する。
- 接続先がtestingでない場合はRuntimeExceptionを送出する。
- 一部のテストではbeforeRefreshingDatabaseでも追加確認する。
- RefreshDatabaseを使用してテストデータを分離する。
- 開発用DBを対象とする初期化コマンドは実行しない。

### DB接続確認結果

- DatabaseConnectionTest：1件成功
- アサーション：6件成功
- 実行時間：0.26秒
- 接続先：testing
- エラー・警告の報告なし

## 4. Factory・Seeder

### Factory

| ファイル | 主な役割 |
|---|---|
| UserFactory | 会員生成・パスワードのハッシュ化 |
| BookFactory | 書籍・登録者・関連ジャンルの生成 |
| GenreFactory | 一意なジャンル名の生成 |
| ReviewFactory | 投稿者・書籍・評価・コメントの生成 |

BookFactoryはafterCreatingでジャンルを自動的に紐付ける。
テストデータ作成時は、この既存の紐付けを考慮する。

### Seederの依存順序

1. UserSeeder
2. GenreSeeder
3. BookSeeder
4. ReviewSeeder
5. FavoriteSeeder
6. ReviewLikeSeeder

Seeder固有のデータ生成条件を、
アプリケーション全体の入力制約として扱わない。

## 5. テスト構成

| テストスイート | ケース数 |
|---|---:|
| Unit | 1 |
| Feature | 448 |
| 合計 | 449 |

ケース数はデータプロバイダーによる展開後の件数。

Unitは初期サンプルテストのみであり、
正式要件で指定されたモデルのUnit Testは未整備。

モデル・DB制約・リレーション・Seederについては、
既存Feature Testを活用し、不足する観点を補完する。

## 6. 全体テストの基準検証結果

| 項目 | 実測値 |
|---|---:|
| 成功件数 | 449 |
| アサーション | 5046 |
| 失敗・エラー | 0 |
| 実行時間 | 36.070秒 |
| メモリ使用量 | 68.50 MB |

先行Issue #21の全体テスト結果と一致した。

## 7. コードカバレッジの基準値

Xdebugを使用し、PCOVを無効化して計測した。
phpunit.xmlの計測対象は変更していない。

| 指標 | カバー数 / 総数 | カバレッジ |
|---|---:|---:|
| クラス | 45 / 56 | 80.36% |
| メソッド | 124 / 138 | 89.86% |
| 行 | 1002 / 1072 | 93.47% |

基本機能の行カバレッジ目標60%超を達成した。
応用機能を含む80%以上の目標は、応用機能実装後に再判定する。

### 主な実装領域

以下の領域は行カバレッジ100%を確認した。

- 基本機能および公開APIの具象Controller
- モデル4クラス
- Policy3クラス
- 認証FormRequest
- 公開API FormRequest・Resource
- BookWriteService

### 100%未満の主なクラス

| クラス | 行カバレッジ |
|---|---:|
| ApiExceptionRenderer | 63.64% |
| Console Kernel | 66.67% |
| LoginResponse | 94.23% |
| StoreReviewRequest | 96.43% |
| BookConstraintViolation | 96.67% |

上記は計測結果に表示された主なクラスであり、
未実行箇所の完全な一覧ではない。

カバレッジ100%を目的とした不要なテスト追加は行わず、
正式要件と実装上のリスクに基づいて不足を補完する。

## 8. SQL取得効率の回帰確認

| 検証対象 | 実測値 |
|---|---|
| 公開API書籍一覧 | 書籍1件・10件ともに3回 |
| 公開API書籍詳細 | レビュー1件・10件ともに6回 |
| 最大ページ番号指定 | 件数取得SQLを確認 |

実際の大規模負荷試験・並行競合試験とは区別する。

## 9. 再現手順

Laravel Sailのコンテナが稼働している状態で実行する。

### テスト用DBへの接続確認

    ./vendor/bin/sail artisan test tests/Feature/DatabaseConnectionTest.php

### 通常の全体テスト

    ./vendor/bin/sail artisan test

### カバレッジ計測

    docker compose exec -T -u sail \
        -e XDEBUG_MODE=coverage \
        laravel.test \
        php -d pcov.enabled=0 \
        vendor/bin/phpunit --coverage-text

カバレッジ計測時のみXdebugを有効化する。
通常時のコンテナ設定は変更しない。

## 10. 後続への引継ぎ

- 正式要件で指定されたモデルのUnit Testを整備する。
- 既存Feature Testと正式要件の不足を照合する。
- シート8-1のコーチ指摘事項を実装とテストへ反映する。
- 仕様変更後に全体テストとカバレッジを再計測する。
- 先行検証で残ったPint整形違反を是正・再確認する。
- 新規取得環境でREADMEの再現検証を行う。

全体テストの成功およびカバレッジ目標の達成は、
正式要件への完全適合を意味しない。

コーチ指摘事項、個別要件との対応、
未確認事項はms10-test-matrix.mdで管理する。

今回の計測では分岐・パスカバレッジを取得していない。
また、開発用DBの計測前後のハッシュ比較は実施していない。

## 11. 関連資料

- [認証仕様・検証記録](authentication.md)
- [書籍管理の実装・検証記録](book-management.md)
- [レビュー機能の実装・検証記録](review-validation.md)
- [ジャンル管理の実装・検証記録](genre-management.md)
- [MS6検証記録](ms6-verification.md)
- [MS8統合検証記録](ms8-verification.md)
- [MS9統合検証記録](ms9-verification.md)
- [公開API詳細設計](api-design.md)
