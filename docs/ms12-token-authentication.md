# MS12：Bearer認証・トークン運用・検証記録

> 本文はIssue #67時点の検証履歴。
> 登録者自動設定・外部user_id指定拒否の現在の状況は、
> [Issue #68検証記録](ms12-owner-input-verification.md)を参照。

## 対象と根拠

- 記録日：2026-11-24
- 対象Issue：#67
- 作業ブランチ：feature/issue-67-sanctum-auth
- 基準コミット：e93e7e59b91daf8cd67b6969c9cc561909e01b4c
- 検証対象：基準コミットにIssue #67の変更を加えたコミット前の作業ツリー
- 要件：Bookshelf要件シート20261121版、シート3 E32、シート13 H6:H10・D28
- PM回答：H072・H073の承認内容と、H086の対応方法・バージョン指定なし
- 実装コミット・PR・マージ結果はGit工程で確定後に記録する。

## 認証方式と実装範囲

GETの書籍一覧・詳細は公開を維持する。
書籍のPOST・PUT・DELETEはauth:sanctumで保護する。

導入済みSanctumはWeb guardを先に確認する実装だった。
Bearer必須の要件に合わせ、sanctum.guardを空配列とした。
これは開発側の実装判断であり、PMの個別回答とは区別する。
既存のGET /api/userもBearer認証に統一される。
Web画面のセッション認証には変更を加えていない。

UserのHasApiTokensと既存personal_access_tokensを再利用した。
Migrationの追加・依存更新・トークン管理画面・発行APIの追加は行っていない。

## 検証用トークンの発行・利用・失効

検証はBookTokenAuthenticationTest内で行う。
専用DB testingとRefreshDatabaseを使用し、開発用DBには発行しない。

1. Factoryで検証用会員と書籍・関連データを作成する。
2. 会員のcreateTokenで名前issue-67-test、abilitiesは['*']、
   有効期限は現在時刻の1時間後として発行する。
3. 戻り値のplainTextTokenをAuthorization: Bearerヘッダーに設定する。
   トークンの実値は表示・ログ出力・資料転記しない。
4. 失効ケースでは戻り値のaccessTokenをdeleteし、
   発行時のBearerトークンで再利用できないことを確認する。
5. 期限切れケースでは期限を1分前として発行する。
   無効ケースでは実在するトークンIDに不一致の文字列を組み合わせる。
6. テスト終了時にDB変更をロールバックし、検証用データを残さない。

認証状態の模擬だけで済ませず、実際のBearer認証経路を使用した。
Web認証だけ、およびWeb認証と無効Bearerの併用でも拒否されることを確認した。

再実行は次の順序とする。

    ./vendor/bin/sail artisan test tests/Feature/DatabaseConnectionTest.php
    ./vendor/bin/sail artisan test tests/Feature/Api/V1/BookTokenAuthenticationTest.php

## 検証結果

| 対象 | 件数 | assertions |
|---|---:|---:|
| DB接続確認 | 1 | 6 |
| 実トークン認証 | 21 | 233 |
| 公開GET一覧 | 7 | 71 |
| 公開GET詳細 | 8 | 75 |
| Webログインセッション | 1 | 16 |
| Web認証全体（上記セッションテストを含む） | 86 | 827 |
| Web書籍認可 | 3 | 15 |

上記対象はすべて成功。重複実行分を含むため、合計件数には集約しない。
変更PHP3ファイルのPintと差分チェックも成功した。

実トークン認証ではPOST・PUT・DELETEごとに、
なし・無効・失効・期限切れ・Webのみ・Webと無効Bearer・有効Bearerを確認した。
拒否時は401 JSON、Locationなし、書籍関連7テーブルの状態保持を確認した。
有効BearerのPOST・PUTは空入力に対する422、DELETEは本人の書籍の204を確認した。
削除時はレビュー・いいね・お気に入り・ジャンル紐付けの削除と会員の保持を確認した。

## 互換性警告

HasApiTokens.phpの構文確認で、createTokenのexpiresAt引数に関する
暗黙的nullable指定の非推奨警告を再確認した。構文エラーはない。
同一警告が標準出力・標準エラーに表示されたもので、別々の問題とは数えない。

通常テストの出力に警告がなくても、解消済みとは扱わない。
vendorの直接修正・警告抑制・依存更新は行っていない。

## 未完了事項と引継ぎ

- H072の登録者自動設定と外部user_id指定拒否は後続Issueで実装する。
- H073の所有者認可・認可優先・403等の統合検証は後続Issueで行う。
- 未認証CRUDを期待する既存APIテスト群はまだ更新していない。
- 今回の変更後の全体テスト・カバレッジは未実施。
- GET /api/user自体のHTTP動作確認は未実施。
- H076〜H079等の未回答事項はAPI設計案と区別して追跡する。
- 応用API全体の安全性・完成を示す記録ではない。
