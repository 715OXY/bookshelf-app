# MS12：登録者入力仕様・検証記録

> 本文はIssue #68時点の検証履歴。
> 所有者認可と入力検証の実行順序は、
> [Issue #69検証記録](ms12-api-authorization-verification.md)を参照。

## 対象と根拠

- 記録日：2026-11-24
- 対象Issue：#68
- 作業ブランチ：feature/issue-68-api-book-owner
- 基準コミット：5c88461df0f9e8aa93a4137b817cadec6a5a096f
- 検証対象：基準コミットに今回の実装・テスト変更を加えた作業ツリー
- 要件：Bookshelf要件シート20261121版、シート8 E29、シート13
- PM回答：H072・H074・H084・H088
- 実装コミット・PR・マージ結果はGit工程で確定後に記録する。

## 変更内容と責務

StoreBookRequestのuser_id必須をmissingへ変更した。
POST・PUTとも入力での登録者指定を拒否する。
POSTの拒否文言「登録者IDは指定できません。」は開発側の定義。
PUTの既存文言「登録者IDは変更できません。」は維持した。

Controllerは検証済みの書籍情報からgenres・user_idを除外し、
認証ユーザーのgetAuthIdentifier()で登録者IDを設定する。
ジャンル同期・トランザクションは既存BookWriteServiceを使用する。

更新時は所有者を変更せず、ISBNの自身除外を維持した。
出版日はNOT NULLのMigrationと整合する必須入力を維持し、
ISBN・ジャンル・description・image_urlの入力条件も変更していない。
Web/API共通項目の既存日本語文言を維持した。
Migration・Model・依存パッケージ・Web実装は変更していない。

## 検証結果

| 対象 | 件数 | assertions |
|---|---:|---:|
| テスト専用DB接続 | 1 | 6 |
| 登録FormRequest | 23 | 115 |
| 更新FormRequest | 6 | 19 |
| 一覧FormRequest | 14 | 42 |
| 正式APIの登録者・入力指定拒否 | 12 | 132 |
| Issue #67のBearer認証 | 21 | 233 |
| 公開GET一覧 | 7 | 71 |
| 公開GET詳細 | 8 | 75 |
| Web書籍CRUD | 3 | 27 |
| Web書籍入力検証 | 36 | 228 |

上記対象はすべて成功。DB接続確認は複数回実施した。
変更PHP7ファイルのPint・構文確認、Git差分チェックに成功した。

入力検証専用ルートのテストと正式APIのテストを区別する。
正式APIでは実際に発行したBearerトークンを使用し、
登録者指定なしの201、登録者の認証会員との一致、ジャンル保存を確認した。
POST・PUTの本人ID・他人ID・NULL・空文字・空配列指定は、
Accept省略時も日本語422 JSONとなり、業務7テーブルを変更しない。
本人による更新の200、自身のISBN使用、所有者保持、
ジャンル同期とレビュー・いいね・お気に入り等の保持を確認した。
トークン実値はログ・資料へ記載していない。

## 後続への接続条件・未完了事項

- 所有者認可と403の統合検証はIssue #69で行う。
- FormRequestはController本体より先に評価されるため、
  Controller本体のauthorizeだけでは認可優先にならない。
  更新のFormRequest評価時にもBookPolicyを適用できる構成を検討する。
- 共通ApiFormRequestへ認証必須条件を追加せず、公開GETを維持する。
- 既存APIテスト群全体の認証・入力仕様更新は未完了。
- 変更後の全体テスト・カバレッジ計測は未実施。
- H077のPUT任意項目省略時の扱いはPM未回答で、現行実装を維持した。
- 出版日のnullable化は別の応用差分として対応する。
- Sanctum非推奨警告はIssue #67の残課題を継続する。
  今回のテスト出力に警告がないことを解消の根拠にはしない。

## 先行Issueの確定情報

- Issue #67：Closed（completed）
- 実装コミット：a33aceb4701350f522f2b7ccbb1a5f14475f8141
- PR #71：Merged
- マージコミット：5c88461df0f9e8aa93a4137b817cadec6a5a096f

上記は先行Issueの確定情報であり、今回のIssue #68のGit記録とは区別する。
