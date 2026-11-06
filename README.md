# Bookshelf

書籍情報の管理・レビュー投稿・お気に入り登録などを行う、
COACHTECHの新模擬案件「Bookshelf」の開発リポジトリです。

## プロジェクト概要

Laravelを用いて、書籍レビューアプリケーションを開発します。
提供された要件シート・Bladeテンプレートと、PMへのヒアリング結果に基づき実装します。

- 開発開始日：2026年10月24日
- 提出期限：2026年12月8日
- 開発方針：LMSのマイルストーンに沿って、基本機能から応用機能へ順次実装

## 主な実装予定機能

以下は実装予定の機能であり、実装済み一覧ではありません。

| 区分 | 機能 |
|---|---|
| 認証 | 会員登録・ログイン・ログアウト |
| 書籍管理 | 書籍一覧・詳細表示・登録・編集・削除 |
| ジャンル管理 | ジャンル一覧・詳細表示・登録・編集・削除 |
| レビュー | レビュー投稿・編集・削除・いいね |
| お気に入り | お気に入り登録・解除・一覧表示 |
| ランキング | 書籍ランキング表示 |
| API | 要件に沿った書籍情報API |
| 応用機能 | 書籍検索・ISBN検索・読書統計・読書計画・通知など |

各機能の仕様・実装範囲は、要件シートと個別のヒアリング結果に従います。

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

現在は、マイルストーン「2. 環境構築・認証基盤」に取り組んでいます。

- アプリ・MySQL・phpMyAdminのコンテナ起動を確認済み
- LaravelからMySQLへの接続を確認済み
- 基本機能用の提供リソースを移入済み
- フロントエンドのビルド・開発サーバー起動を確認済み
- 標準Migrationの適用と、再起動後の保持を確認済み
- 会員登録・ログイン・ログアウトを実装し、ブラウザーとFeature Testで確認済み

環境構築の再現手順は整備中です。
新規取得した環境での手順検証は、今後実施します。

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
現在のDatabaseSeederは空で、業務用初期データは後続で追加する。

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

アプリの認証・業務画面は後続タスクで実装する。

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

### DB・再起動の確認結果

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

2FA用カラムはFortify導入時に照合する。
業務テーブル・業務用Seederは後続マイルストーンで実装する。
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
- 認証画面のエラー表示・入力保持・実際の画面動作は、
  Fortify導入後に検証する。
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
- 現在の `/` は初期画面。書籍一覧への接続は後続で実装する

入力条件・文言の実装案と確認状況は、
[認証仕様・検証記録](docs/authentication.md)を参照。

### テスト

テスト用DBは `testing`、開発用DBは `laravel`。
テスト起動時に環境と実際の接続先を確認している。
DBを使用する認証テストでは、testing内のテーブルを再構築する。

```bash
./vendor/bin/sail test
```

2026-11-06の確認結果：

- 全63テスト・486アサーション成功
- 登録・ログイン・ログアウト・入力保持・日本語エラーを確認
- 境界値・不正な型・重複時のDB不変を確認
- 安全な元URLへの復帰・クエリ保持・不適切な戻り先の除外を確認
- CSRF検証を有効にしたテストで、不備時の419と状態不変を確認
- Laravel Pintによる対象コードの整形を実施

PHP 8.5で非推奨となるPDO定数は、config/database.phpで
PHPバージョンに応じた定数を使用するよう変更した。
