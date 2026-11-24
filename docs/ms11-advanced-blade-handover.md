# MS11：Advanced版Bladeテンプレート導入・引継ぎ記録

- 対象Issue：#60
- 記録日：2026-11-21
- 対象ブランチ：`feature/issue-60-advanced-blade`
- 提供リポジトリ：`coachtech-prepared-file/Preparedblade-mockcase-BookShelf`
- 提供ブランチ：`advanced`
- 提供コミット：`a9c6967`
- 根拠：Bookshelf最新版要件シート（2026-11-14版）、
  Advanced版提供リソース、既存実装・検証結果

## 1. 目的と実装範囲

基本フェーズの既存機能を維持しながら、
応用フェーズ用Bladeテンプレートを先行導入する。

未実装の応用機能に関するController、ルート、
DBテーブル、API処理はIssue #60では追加しない。

## 2. 導入したテンプレート

### 新規追加（5ファイル）

- `resources/views/notifications/index.blade.php`
- `resources/views/reading-plans/create.blade.php`
- `resources/views/reading-plans/edit.blade.php`
- `resources/views/reading-plans/index.blade.php`
- `resources/views/reports/index.blade.php`

### 変更（Blade 7ファイル）

- `resources/views/books/_form.blade.php`
- `resources/views/books/create.blade.php`
- `resources/views/books/edit.blade.php`
- `resources/views/books/index.blade.php`
- `resources/views/books/show.blade.php`
- `resources/views/favorites/index.blade.php`
- `resources/views/layouts/navigation.blade.php`

### 互換性調整（Controller 1ファイル）

- `app/Http/Controllers/BookController.php`
  - 編集画面用の一時的な出版日文字列キャストを削除
  - BookモデルのdateキャストとAdvanced版Bladeの
    `format('Y-m-d')` を整合させた

提供リポジトリの原本は、開発リポジトリ外の
`/home/oxy/projects/bookshelf-prepared-advanced` に保持する。

## 3. 既存機能維持のための暫定対応

- ISBNと出版日は、既存の必須バリデーション、
  DBのNOT NULL制約、画面の必須マークを維持した。
- ISBN検索フォームとJavaScriptは、
  `Route::has('books.isbn')` で表示・実行を制御した。
- 書籍一覧の高度な検索フォームは、
  `$genres` の提供有無で表示を制御した。
- 検索機能が未実装の間は、従来の書籍登録導線と
  書籍0件時の案内を維持した。
- レポート・読書計画・通知のナビゲーションは、
  対応する名前付きルートの存在により表示を制御した。
- 通知の未読件数取得は、通知一覧ルートが
  存在する場合に限って実行するよう制御した。

これらは移行時の暫定的な実装案であり、
後続機能の完成・正式なルート設計との整合は未確認。

## 4. 検証結果

- Laravel Sail・MySQL：正常稼働
- BookControllerのPHP構文チェック：成功
- Bladeコンパイル：成功
- 関連Feature Test：152件PASS、1248 assertions
- 全自動テスト：623件PASS、Deprecated 1件、
  失敗0件、合計624件、7175 assertions
- 行カバレッジ表示値：93.9%
- BookControllerのカバレッジ：100%
- 書籍一覧・詳細の取得クエリ数：データ件数増加による増加なし
- ブラウザ：書籍一覧・詳細・ランキング、
  認証画面・登録編集画面・お気に入り・ジャンル管理を確認
- 未実装の検索フォーム・応用機能リンク：非表示を確認
- ログアウト：正常
- `git diff --check`：問題なし

上記のカバレッジは現時点の既存コードに対する結果であり、
未実装の応用機能の品質を保証するものではない。

## 5. 後続マイルストーンへの引継ぎ

| 分類 | 引継ぎ事項 | 状態 |
|---|---|---|
| Web書籍検索 | 検索・絞り込み・ソート処理と検索フォームを接続 | 未実装 |
| ISBN検索 | Google Books API取得処理、ルート、画面JSの接続 | 未実装 |
| マイレポート | 認証済みユーザーの統計集計・画面接続 | 未実装 |
| 読書計画 | Model・Migration・認証認可・CRUD・状態管理 | 未実装 |
| 通知 | 通知DB・一覧・既読化・未読件数取得 | 未実装 |
| 書籍項目 | ISBN・出版日の任意項目化とDB制約の整合 | 要件に沿って後続で確認 |
| 表示制御 | 暫定条件を正式実装時に再評価 | 要再確認 |
| 品質 | Sanctum関連テストのDeprecated警告 | 原因未確定 |
| 品質 | フォームのlabel関連アクセシビリティ指摘 | 詳細箇所未確定 |
| 品質 | コンソールの非表示メッセージ内容 | 未確認 |

### 後続で注意する点

- `books.isbn` は暫定的に採用したルート名。
  JSには `/books/isbn/{isbn}` が記載されている。
  後続実装時にルート名・URL・認証・応答形式を照合する。
- `$genres` の有無は検索機能の完成を直接保証しない。
  Controllerの機能追加時に表示条件を見直す。
- 通知一覧ルートの存在だけでは、
  通知テーブルやUserモデルの関連の準備完了を保証しない。
- 新規Blade 5ファイルは導入済みだが、
  Controllerからの描画・画面操作は未検証。
- ISBNと出版日の任意項目化では、
  FormRequest・Migration・Model・API・テストの整合を取る。
- Deprecated警告はLaravel Sanctum関連として表示されたが、
  警告全文と原因は未確認。
- Chrome Issuesの「No label associated with a form field」は
  アクセシビリティ上の指摘であり、該当要素は未特定。
- 新規取得環境でのREADME全手順の再現検証は未実施。

## 6. 変更管理と提出前の確認

今回の変更理由は、基本フェーズ完了後にAdvanced版の
提供Bladeを導入するという公式要件への対応である。

影響範囲は、書籍画面・お気に入り画面・共通ナビゲーション・
書籍編集時の日付処理および新規応用画面テンプレート。

後続の機能実装では、最新公式要件・本案件へのPM回答・
既存設計・テストを照合したうえで、
暫定実装の見直し箇所を記録する。

Issue #60の確定情報：
- 実装コミット：373ed801720a6d61250734b10295f8f5eff1b40a
- PR：#64（Merged）
- マージコミット：2ec1df158e1d6acc84e66914d4dbc23b0e1fb6ba

以降の警告内容・画面確認・PM回答・保留事項の最新状態は
[MS11統合検証記録](ms11-integration-verification.md)を参照する。
上記の原因未確認等の記述はIssue #60当時の履歴として扱う。
