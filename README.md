# Bookshelf

書籍情報の管理・レビュー投稿・お気に入り登録などを行う、
COACHTECHの新模擬案件「Bookshelf」の開発リポジトリです。

## プロジェクト概要

Laravelを用いて、書籍レビューアプリケーションを開発します。
提供された要件シート・Bladeテンプレートと、PMへのヒアリング結果に基づき実装します。

- 開発開始日：2026年10月24日
- 提出期限：2026年12月8日
- 開発方針：LMSのマイルストーンに沿って、基本機能から応用機能へ順次実装

## 主な機能と実装状況

2026年11月19日時点の実装・検証状況。

| 区分 | 機能 | 状態 |
|---|---|---|
| 認証 | 会員登録・ログイン・ログアウト | 実装済み |
| 書籍管理 | 書籍一覧・詳細表示・登録・編集・削除 | 実装済み |
| ジャンル管理 | ジャンル一覧・詳細表示・登録・編集・削除 | 実装済み |
| レビュー | レビュー投稿・編集・削除・いいね | 実装済み |
| お気に入り | お気に入り登録・解除・一覧表示 | 実装済み |
| ランキング | 書籍ランキング表示 | 実装済み |
| 公開API | 書籍一覧・詳細・登録・更新・削除 | 実装・動作検証済み |
| 応用API | Sanctum認証・所有者認可 | 未実装 |
| 応用機能 | Web書籍検索・ISBN検索・読書統計・読書計画・通知など | 未実装 |

各機能の仕様・実装範囲は、要件シートと個別のヒアリング結果に従います。
実装済みの表記は、未確定事項についてのPM承認を意味しません。

## 使用技術

| 用途 | 技術 |
|---|---|
| バックエンド | PHP / Laravel 10 |
| 認証基盤 | Laravel Fortify |
| データベース | MySQL 8.4 |
| フロントエンド | Blade / Tailwind CSS 3 / Alpine.js |
| アセット管理 | Vite |
| 開発環境 | Docker Desktop / Docker Compose / Laravel Sail |
| DB管理 | phpMyAdmin |
| 開発ツール | VSCode / WSL2（Ubuntu） |

実際に確認したバージョンは、後述の「環境構築の作業記録」に記載します。

## 開発状況

更新日：2026年11月19日

現在は、マイルストーン9の公開APIについて、
最終統合検証と資料更新を進めています。

- MS1〜MS8の既存機能を実装済み。
- 公開APIの基本要件AP01〜AP05を実装・動作検証済み。
- 全体回帰テスト443件・4949 assertions成功。
- 公開APIテスト159件・2012 assertions成功。
- 最終HTTP疎通確認19件成功。
- 開発用DBの関連7テーブルについて、HTTP検証前後の内容一致を確認済み。
- 既存Web機能への回帰影響は自動テストの範囲内で検出されていない。

MS9の最終コミット・PRマージ・要件シートへの反映は別途管理します。
応用APIの認証・認可、PM確認事項、未実施の検証は後続工程に引き継ぎます。

新規取得環境でのREADME手順の再現検証は未実施です。

## 設定情報の取り扱い

- `.env`はGit管理せず、設定項目は`.env.example`で共有します。
- `APP_KEY`は各開発環境で生成します。
- 個人用のパスワード・APIキー・トークンはコミットしません。
- READMEに記載したDB接続情報は、ローカル開発用の設定例です。

## 仕様・変更の管理

実装は、Bookshelfの最新公式要件と、本案件に対するPMの明示回答を根拠とします。

仕様や構成を変更する場合は、理由・根拠・影響・反映先を記録します。
未確定の詳細は実装案として扱い、機能ごとに実装・テスト・設計書の整合を確認します。

特に、入力条件・バリデーションメッセージ・フラッシュメッセージを、
各機能の動作と併せて確認します。

## 新規取得時の環境構築手順

以下はmainへの環境構築ブランチ反映後に使用する手順です。
新規取得環境での一連の実行検証は未実施です。

### 1. 前提条件

- Docker Desktopが起動していること
- 作業用UbuntuでWSL integrationが有効であること
- WSL2のBashターミナルで実行すること
- ホスト側の80・3306・5173・8080番ポートが使用可能であること

### 2. リポジトリを取得する

```bash
mkdir -p ~/projects
cd ~/projects
git clone https://github.com/715OXY/bookshelf-app.git
cd bookshelf-app
```

既存の作業フォルダーがある場合は、別の場所へ取得する。

### 3. 環境変数を設定する

```bash
cp .env.example .env
id -u
id -g
```

.envのWWWUSERにはid -u、WWWGROUPにはid -gの結果を設定する。
DB接続情報は後述の「DB設定」を参照する。

### 4. PHP依存パッケージを導入する

シート4指定の作成用イメージを使用する。
当局回答に基づく作成用環境と、アプリ実行用環境は区別する。

```bash
docker pull laravelsail/php82-composer:latest

docker run --rm -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  -e COMPOSER_CACHE_DIR=/tmp/composer_cache \
  laravelsail/php82-composer:latest \
  composer install
```

composer.lockに記録された依存関係を使用するため、
新規取得時にcreate-projectやcomposer updateは実行しない。

### 5. コンテナをビルド・起動する

```bash
docker compose config --quiet
docker compose build --pull --no-cache laravel.test
./vendor/bin/sail up -d
./vendor/bin/sail ps
./vendor/bin/sail php -v
./vendor/bin/sail composer check-platform-reqs
```

アプリ実行用PHPが、当局通知の修正版8.5.12以上であることを確認する。
ビルド元の配布状況によって実際のパッチ番号は変わる。

### 6. アプリケーションキー・DBを初期設定する

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan migrate:status
```

key:generateは新規環境の初期設定時に実行する。
DatabaseSeederから指定の基本データを投入する。
初回投入は空のアプリケーションDBを前提とする。
既存データがある場合は、以下の「基本DB・初期データ」を確認してから実行する。

### 7. フロントエンドをセットアップする

```bash
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

package-lock.jsonの依存関係を使用する。
esbuildのスクリプト許可はpackage.jsonに記録済み。

提供済みの基本版resourcesはリポジトリに含まれるため、
新規取得時の再移入は不要。

### 8. 開発を開始する

別のターミナルで以下を実行し、開発中は起動を維持する。

```bash
./vendor/bin/sail npm run dev
```

起動・停止方法と接続先は、後述の作業記録を参照する。

## 環境構築の作業記録

確認日：2026-11-05

### 開発環境

- Windows / WSL2（Ubuntu 24.04）
- Docker Desktop / Docker Compose / Laravel Sail
- Laravel：10.50.4
- アプリ実行用PHP：8.5.12
- MySQL：8.4.11
- Node.js：24.22.2
- npm：12.2.2
- Vite：7.3.7
- laravel-vite-plugin：2.1.0
- Tailwind CSS：3系

### 環境構築時の対応

要件シート「シート4：環境構築手順」を基本として構築した。

- 作成用PHP・Composerの問題は、当局回答に従って対応した。
  回答原文・適用設定の詳細はヒアリング記録を参照する。
- アプリ実行用PHPは、当初8.5.11だったため、
  キャッシュを使用せず再ビルドし、8.5.12へ更新した。
- npm監査の指摘に対応するため、Viteを5系から7.3系へ、
  laravel-vite-pluginを1系から2.1系へ更新した。
- esbuild@0.28.2のインストールスクリプトを個別に許可した。
- 更新後のnpm auditは脆弱性0件。
  Composer側で通知された脆弱性とは別の確認結果である。

### 提供リソース

- リポジトリ：
  https://github.com/coachtech-prepared-file/Preparedblade-mockcase-BookShelf
- ブランチ：basic
- コミット：c3418c25a4d81a63b8e2c555d20562b283d75255

resources配下へ移入後、npm run buildの成功を確認した。

### 起動・停止

プロジェクトのルートで実行する。

```bash
# コンテナ起動
./vendor/bin/sail up -d

# 状態確認
./vendor/bin/sail ps

# Vite起動（別ターミナルで実行し、開発中は起動を維持する）
./vendor/bin/sail npm run dev
```

停止時はViteのターミナルでCtrl+Cを押し、次を実行する。

```bash
./vendor/bin/sail down
```

DBデータを保持する通常の停止では、downに-vを付けない。

### 接続先

- アプリ：http://localhost
- Vite：http://localhost:5173
- phpMyAdmin：http://localhost:8080
- 認証画面`/login`・`/register`
- トップページ（書籍一覧）：http://localhost/
- 公開API：http://localhost/api/v1/books

基本版の認証画面・業務画面・公開APIを実装済み。

### DB設定

ローカル開発用の設定例：

```dotenv
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password
```

WWWUSER・WWWGROUPには、WSLでid -u・id -gを実行した値を設定する。
今回の環境では、どちらも1000。

.envはGit管理せず、APP_KEYは各環境で生成する。
APP_KEY生成と設定キャッシュのクリアは実施済み。

### DB・再起動の確認結果（2026-11-05時点の履歴）

- Laravel経由でDB「laravel」へ接続成功。
- MySQLサーバー版は8.4.11。
- phpMyAdminで以下の5テーブルを確認：
  users / password_reset_tokens / failed_jobs /
  personal_access_tokens / migrations
- migrate --seedは成功。現時点のDatabaseSeederは空で、
  初期データの投入は行っていない。
- 標準Migration4本はすべてRan。
- コンテナ停止・再起動後もDB接続とMigrationの保持を確認。
- Viteの再起動も成功。

上記は環境構築時点の記録。
その後、2FAの3列を追加・照合し、基本業務テーブルとSeederを実装した。
現在の確認結果は「基本DB・初期データ」を参照する。
新規取得環境での構築手順の検証は未実施。

## 日本語化・フロントエンド基盤

- アプリ名をBookshelfに設定。
- config/app.phpのlocaleをjaに設定。
- シート4の指定に従い、lang/ja配下へ以下を手動配置：
  - auth.php：認証メッセージ
  - validation.php：共通バリデーションメッセージ・項目名
  - pagination.php：ページ送り
- laravel-lang/*系パッケージは導入していない。
- Basic提供リソースのAlpine.js・Tailwind初期化を確認。

### 確認結果

- 日本語ロケールと認証メッセージの取得成功。
- required・email・max・confirmedについて、
  エラーキー・日本語項目名・メッセージ・文字数の置換を確認。
- ページ送りの翻訳取得成功。
- Bladeコンパイルとフロントエンドビルド成功。

### 後続で確認する事項

- 現在のバリデーション文言は実装案。
  各機能の入力ルール・PM回答と照合し、必要な翻訳を追加する。
- 認証画面のエラー表示・入力保持・画面動作については、
  後述の認証機能と認証仕様・検証記録を参照する。
- auth.throttleの翻訳キーの存在は、試行回数制限の有効化を意味しない。
  認証実装ではH019「試行回数制限は必要ありません。」を反映する。

## 認証機能

Laravel Fortify 1.36.2を使用し、会員登録・ログイン・ログアウトを実装した。
入力検証はアプリ側のFormRequestへ分離している。

- 登録成功後：自動ログインして `/` へ遷移
- ログイン成功後：安全な元の認証必須GET画面へ復帰。
  有効な戻り先がない場合は `/` へ遷移
- ログアウト後：セッションを無効化し `/login` へ遷移
- 認証済みでログイン・登録へアクセスした場合：`/` へ遷移
- ログイン試行回数制限：H019に従い設けない
- 2FA：基本版の指定3列をNULL許容で保持し、機能は無効
- 現在の `/` は書籍一覧画面

入力条件・文言の実装案と確認状況は、
[認証仕様・検証記録](docs/authentication.md)を参照。

### テスト

テスト用DBは `testing`、開発用DBは `laravel`。
テスト起動時に環境と実際の接続先を確認している。
DBを使用する認証テストでは、testing内のテーブルを再構築する。

```bash
./vendor/bin/sail test
```

2026-11-06の認証実装時点の確認結果（DB実装テスト追加前）：

- 全63テスト・486アサーション成功
- 登録・ログイン・ログアウト・入力保持・日本語エラーを確認
- 境界値・不正な型・重複時のDB不変を確認
- 安全な元URLへの復帰・クエリ保持・不適切な戻り先の除外を確認
- CSRF検証を有効にしたテストで、不備時の419と状態不変を確認
- Laravel Pintによる対象コードの整形を実施

PHP 8.5で非推奨となるPDO定数は、config/database.phpで
PHPバージョンに応じた定数を使用するよう変更した。

## 基本DB・初期データ

### 実装・検証状況

- 基本業務テーブル：books / genres / reviews / book_genres / favorites / review_likes
- users・2FA列を含む基本7テーブルを、テーブル仕様書・ER図と照合済み。
- Model・Factory・双方向リレーションを実装済み。
- 複数レビュー、自己いいね、中間テーブルの重複禁止、評価CHECK、削除時動作の対象テストが成功。
- 全Seederをテスト用DBへ投入し、指定値・件数・関連・パスワードのハッシュを確認済み。
- 2026-11-06：全76テスト・1017アサーション成功。
- 2026-11-06：許可を得て認証確認ユーザー1件を削除し、開発用DB `laravel` への指定データ投入を実施済み。
- 投入後件数：ユーザー5、ジャンル10、書籍11、レビュー32、書籍ジャンル紐付け16、お気に入り19、レビューいいね48。
- 全書籍の登録者が山田太郎であることを確認。投入後の全テストも76件・1017アサーション成功。
- 新規取得環境でのREADME全手順の再現検証は未実施。

### 初期データの投入

空のアプリケーションDBへ、Migrationと初期データを投入する。
既存データのあるDBでは、先に内容を確認する。

```bash
./vendor/bin/sail artisan migrate --seed
```

Migration適用済みで、ユーザー・業務データが空の場合は次を使用する。

```bash
./vendor/bin/sail artisan db:seed
```

呼び出し順：User → Genre → Book → Review → Favorite → ReviewLike。

| 対象 | 初回投入内容 |
|---|---|
| ユーザー | 指定5人。共通パスワードはローカル確認用の `password` |
| ジャンル | 指定10件 |
| 書籍 | 指定11冊。登録者は `User::first()`（空DBからの投入では山田太郎） |
| レビュー | 32件、評価3〜5、各書籍2〜4件 |
| お気に入り | 各ユーザー3〜5冊 |
| レビューいいね | 各レビュー0〜3人。Seederでは投稿者本人を除外 |

- ReviewSeederは `create` を使うため、再実行するとレビューが32件追加される。
- User・Genre・Bookの `firstOrCreate` は、既存レコードの値を更新しない。
- Bookのジャンルは `sync` で同期する。
- お気に入り・いいねの `syncWithoutDetaching` は、既存の関連を削除しない。
- ReviewSeeder・FavoriteSeederは、ユーザー5人・書籍11冊であることを確認してから処理する。
- 既存の認証確認ユーザー等がある場合、そのまま投入すると指定の初期状態にならない。
- アプリでは同一書籍への複数レビューと自己いいねを許可する。
- 基本版の出版日はNOT NULL。nullable化と読書計画等の追加は応用段階で扱う。

### DB関連テスト

テストは専用のMySQLデータベース `testing` を使用する。
`RefreshDatabase` を使うテストではテーブルが再構築されるため、
`testing` へ保持が必要なデータを置かない。
新しいMySQL環境では、`testing` の存在と接続ユーザーの権限を確認する。

```bash
./vendor/bin/sail artisan test --filter='DatabaseConnectionTest|ModelRelationsTest|DatabaseConstraintsTest|DatabaseSeederTest'
```

全テスト：

```bash
./vendor/bin/sail artisan test
```

## 公開API

### 基本仕様

ベースURL：`http://localhost/api/v1`

基本版（MS9）では、以下の5操作に認証を要求しない。
応用版（MS10）では、書き込み操作にSanctum認証と必要な認可を追加する。

基本版の未認証書き込みは課題の検証用仕様であり、
外部公開環境での運用を想定したものではない。

| Method | Endpoint | 内容 | 成功時 |
|---|---|---|---|
| GET | `/books` | 書籍一覧取得 | 200 |
| GET | `/books/{book}` | 書籍詳細取得 | 200 |
| POST | `/books` | 書籍登録 | 201 |
| PUT | `/books/{book}` | 書籍更新 | 200 |
| DELETE | `/books/{book}` | 書籍削除 | 204 |

### 一覧取得の検索条件

| パラメーター | 内容 |
|---|---|
| keyword | タイトル・著者・ISBNの部分一致検索 |
| genre_id | ジャンルIDによる絞り込み |
| page | ページ番号。既定値1、上限2147483647 |
| per_page | 1ページの件数。既定値20、上限100 |

- キーワードとジャンルの条件は組み合わせて指定できる。
- 書籍は作成日時降順、同日時の場合はID降順で取得する。
- 最終ページを超えた場合は空配列を返す。
- 一覧レスポンスはdata・links・metaを含む。
- 各書籍のジャンル・平均評価・レビュー件数を返す。
- 詳細レスポンスにはレビューと投稿者名等も含む。

### 登録・更新の入力仕様

| 項目 | POST | PUT | 入力条件 |
|---|---|---|---|
| user_id | 必須 | 指定不可 | 存在するユーザーID |
| title | 必須 | 必須 | 255文字以下 |
| author | 必須 | 必須 | 255文字以下 |
| isbn | 必須 | 必須 | 半角数字13桁、一意 |
| published_date | 必須 | 必須 | YYYY-MM-DD形式 |
| description | 任意 | 任意 | 10000文字以下 |
| image_url | 任意 | 任意 | URL形式、2048文字以下 |
| genres | 必須 | 必須 | 存在するジャンルIDの配列、1件以上、重複不可 |

- 更新時のISBN重複判定では対象書籍自身を除外する。
- PUTで任意項目を省略した場合はNULLで更新する。
- 書籍とジャンルの保存はトランザクション内で行う。
- 書籍削除時は関連レビュー・いいね・お気に入り・ジャンル紐付けを削除する。
- レビューがない書籍の平均評価はnull、レビュー件数は0となる。

### APIの利用例

一覧取得：

```bash
curl -i 'http://localhost/api/v1/books'
```

検索・ページネーション：

```bash
curl -i 'http://localhost/api/v1/books?keyword=PHP&genre_id=1&page=1&per_page=5'
```

詳細取得：

```bash
curl -i 'http://localhost/api/v1/books/1'
```

書籍登録時のJSONリクエスト例：

```json
{
    "user_id": 2,
    "title": "API検証用書籍",
    "author": "検証用著者",
    "isbn": "9780000000047",
    "published_date": "2026-11-19",
    "description": "検証用データ",
    "image_url": null,
    "genres": [1, 3]
}
```

POST・PUTでJSONを送信する場合はContent-Typeをapplication/jsonとする。
PUTではuser_idを送信しない。

書き込み操作を試す場合は、開発用DBであることを確認し、
実在するユーザー・ジャンルIDと未使用のISBNを指定する。
既存データを無断で更新・削除しない。

### エラー応答

| HTTP Status | 内容 |
|---|---|
| 404 | 対象書籍またはAPIが存在しない |
| 405 | 許可されていないHTTPメソッド |
| 422 | 入力検証エラー |
| 429 | リクエスト回数の上限超過 |
| 500 | 予期しない内部エラー |

入力検証エラーの例：

```json
{
    "message": "入力内容に誤りがあります。",
    "errors": {
        "title": [
            "タイトルを入力してください。"
        ]
    }
}
```

- APIの対象エラーはAcceptヘッダーを明示しない場合もJSONで返す。
- リクエスト回数制限は原則60回/分。
- 削除成功時の204レスポンスには本文を含めない。
- 401・403の認証・認可検証は応用API実装時に行う。

上記の詳細仕様には開発側の実装案を含む。
PM確認事項と例外処理の保証範囲についてはAPI詳細設計を参照する。

### テストの実行

開発用DBではなく、専用のMySQLデータベースtestingを使用する。

```bash
./vendor/bin/sail artisan test tests/Feature/DatabaseConnectionTest.php
./vendor/bin/sail artisan test tests/Feature/Api/V1
./vendor/bin/sail artisan test
./vendor/bin/sail pint --test tests/Feature/Api/V1
```

2026年11月19日のIssue #46検証結果：

| 対象 | テスト件数 | assertions |
|---|---:|---:|
| MS8までの既存テスト | 284 | 2937 |
| MS9公開APIテスト | 159 | 2012 |
| 全体回帰テスト | 443 | 4949 |
| うちIssue #46追加分 | 45 | 624 |

- 最終HTTP疎通検証：19件成功。
- 一覧取得：書籍1件・10件ともにSELECT文3回。
- 詳細取得：レビュー1件・10件ともにSELECT文6回。
- 最大ページ番号指定時：件数取得SQLのみ。
- HTTP検証前後：関連7テーブルの件数とSHA-256が一致。

検証結果は基準コミットにIssue #46の追加テスト4ファイルを
加えた時点のもの。最終コミットIDはPR本文に記録する。

大規模負荷試験、実際の並行競合試験、
コードカバレッジの数値計測、新規取得環境での再現検証は未実施。

## 設計・検証資料

- [認証仕様・検証記録](docs/authentication.md)
- [書籍管理](docs/book-management.md)
- [レビュー入力検証](docs/review-validation.md)
- [MS6検証記録](docs/ms6-verification.md)
- [ジャンル管理](docs/genre-management.md)
- [MS8統合検証記録](docs/ms8-verification.md)
- [公開API詳細設計](docs/api-design.md)
- [MS9統合検証記録](docs/ms9-verification.md)
