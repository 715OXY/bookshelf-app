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
| 認証基盤（導入予定） | Laravel Fortify |
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
- 認証機能・業務機能は後続タスクで実装予定

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
