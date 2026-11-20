# 基本DB設計・ER図

## 1. 対象と根拠

- 記録日：2026-11-20
- 関連Issue：#56
- 要件資料：Bookshelf要件シート20261114版
- 参照：シート11・12、関連PM回答
- 対象：基本機能の実装済み業務テーブル

現行Migration・Model・MySQLのテーブル定義を
最終照合した結果に基づいて記録する。

公式のテーブル仕様書を置き換えるものではない。

## 2. ER図

```mermaid
erDiagram
    users ||--o{ books : registers
    users ||--o{ reviews : posts
    books ||--o{ reviews : receives
    books ||--o{ book_genres : has
    genres ||--o{ book_genres : classifies
    users ||--o{ favorites : registers
    books ||--o{ favorites : receives
    users ||--o{ review_likes : registers
    reviews ||--o{ review_likes : receives

    users {
        bigint id PK
        string email UK
    }

    books {
        bigint id PK
        bigint user_id FK
        string isbn UK
        date published_date
    }

    genres {
        bigint id PK
        string name UK
    }

    reviews {
        bigint id PK
        bigint user_id FK
        bigint book_id FK
        tinyint rating
        text comment
    }

    book_genres {
        bigint id PK
        bigint book_id FK
        bigint genre_id FK
    }

    favorites {
        bigint id PK
        bigint user_id FK
        bigint book_id FK
    }

    review_likes {
        bigint id PK
        bigint user_id FK
        bigint review_id FK
    }
```

ER図には関連を理解するための主要カラムを記載する。
全カラムの型・桁数・NULL制約等は、
公式テーブル仕様書とMigrationを参照する。

## 3. テーブル構成

| テーブル | カラム数 | 役割 |
|---|---:|---|
| users | 11 | 会員 |
| books | 10 | 書籍 |
| genres | 4 | ジャンル |
| reviews | 7 | レビュー |
| book_genres | 5 | 書籍とジャンルの関連 |
| favorites | 5 | 会員のお気に入り書籍 |
| review_likes | 5 | 会員のレビューいいね |
| 合計 | 47 | |

Laravelの標準管理テーブルは上記ER図から省略する。
応用機能の追加テーブルは未実装のため含めない。

## 4. 外部キーと削除時動作

| 参照元 | 参照先 | ON DELETE |
|---|---|---|
| books.user_id | users.id | RESTRICT |
| reviews.user_id | users.id | RESTRICT |
| reviews.book_id | books.id | CASCADE |
| book_genres.book_id | books.id | CASCADE |
| book_genres.genre_id | genres.id | RESTRICT |
| favorites.user_id | users.id | RESTRICT |
| favorites.book_id | books.id | CASCADE |
| review_likes.user_id | users.id | RESTRICT |
| review_likes.review_id | reviews.id | CASCADE |

書籍削除時は関連レビュー・ジャンル紐付け・
お気に入りが削除される。
レビュー削除に伴い、そのレビューへのいいねも削除される。

書籍に紐付くジャンルの削除は許可しない。

## 5. 主要なDB制約

### 一意制約

- users.email
- books.isbn
- genres.name
- book_genres：book_idとgenre_idの組み合わせ
- favorites：user_idとbook_idの組み合わせ
- review_likes：user_idとreview_idの組み合わせ

### 入力値・NULL制約

- reviews.rating：1〜5のCHECK制約
- reviews.comment：NULL許容
- books.published_date：NOT NULL
- books.description・image_url：NULL許容

usersの2FA関連3カラムはNULLを許容する。
基本版では2FA機能を有効化していない。

### 業務上の制約との区別

- 同一ユーザーによる同一書籍への複数レビューを許可する。
- 自己登録書籍のお気に入り登録を許可する。
- 自己レビューへのいいねを許可する。
- レビュー編集時のコメント必須化はFormRequestで扱う。

## 6. 検証記録

- Model4クラス、リレーション12定義を確認。
- 基本業務7テーブルの定義・主要制約を照合。
- Migration11本の適用状況を確認。
- DB制約・リレーション・Seederの回帰検証を実施。
- ジャンル登録・更新・削除の障害時ロールバックを検証。

テーブル構造を変更する場合は、
公式要件・Migration・Model・本資料・関連テストを
併せて確認する。

## 7. 関連資料

- [README](../README.md)
- [MS10モデル・DB関連検証記録](ms10-model-verification.md)
- [MS10最終統合検証記録](ms10-final-verification.md)
