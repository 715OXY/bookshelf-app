# マイルストーン6 検証記録

- 確認日：2026-11-12
- 対象：お気に入り・レビューいいね機能（Issue #17〜#21）
- 状態：サーバー側の検証済み。実画面確認等が残るため、マイルストーン全体は未完了。

## 検証対象

- 基準コミット：11032c8fce98680ef2c276b2c66c756586605433
- 検証時の変更：FavoriteToggleTestの不存在404テスト補強。
- 上記の基準コミットと未コミット差分を合わせた状態で検証した。
- 最終コミットIDは、コミット後にPR本文へ記録する。

## 根拠

- Bookshelf要件シートのS3・S5・S7・S10・S12。
- 保存済みの2026-11-01会議後版の抽出記録と回答整理を参照。
- 主な回答：H010/H011、H023〜H025、H042〜H044、
  H053〜H055、H057/H058。
- 新しい公式資料・追加回答の有無は今回未確認。
- 提供Bladeは変更しない。

## 要件と検証の対応

| 確認対象 | 主なテスト | 状態 |
|---|---|---|
| お気に入り追加→解除→再追加、自己登録書籍 | FavoriteToggleTest | 成功 |
| いいね追加→解除→再追加、自己いいね、件数 | ReviewLikeToggleTest | 成功 |
| 認証、ID偽装防止、他ユーザー・別対象の関連保持 | 両ToggleTest | 成功 |
| 対象なし404と既存関連の保持 | 両ToggleTest | 成功 |
| 本人一覧、10件ページング、並び順、0件、ページ補正 | FavoriteIndexTest | 成功 |
| 一覧・詳細への固定の戻り先 | FavoriteRedirectTest | 成功 |
| CSRF不備419・DB不変、有効トークンでの操作 | 両CsrfTest | 成功 |
| 書き込み後の例外、500、ロールバック、成功通知なし | 両TransactionTest | 成功 |
| 操作後の登録状態・いいね数の再取得 | BookInteractionDataTest | 成功 |
| レビュー1件と10件で取得クエリ数が増えないこと | BookInteractionDataTest | 成功 |
| Model関連、一意制約、削除連動 | ModelRelationsTest・DatabaseConstraintsTest等 | 成功 |
| 提供Bladeのページ描画・ボタン・リンク・ゲスト導線 | 実画面・描画テスト | 保留 |

表示用データのテストはControllerと関連プロパティを検証している。
Bladeの描画やブラウザー操作の検証とは区別する。

## 実装・設計の照合

- favorites.toggle・reviews.like・favorites.indexはauth:webの対象。
- 操作者は認証ユーザー、操作対象はURLから決定する。
- 自己お気に入り・自己いいねを許可する。
- UserとBook、UserとReviewの多対多関連を使用する。
- favoritesはuser_idとbook_id、review_likesはuser_idとreview_idを持つ。
- 両中間テーブルに組み合わせ一意制約とtimestampsがある。
- 書籍削除時はfavorites、レビュー削除時はreview_likesを連鎖削除する。
- 対象Controllerは標準の配置・英語のメソッド名・型宣言・処理説明のPHPDocを使用する。
- 関連取得ではレビュー投稿者・いいねユーザーをEager Loadingしている。
- 本Issueでは本番コード・提供Blade・Model・Migrationを変更していない。

## 採用した実装案と資料との差分

| 項目 | 方針・根拠 | 影響・反映先 |
|---|---|---|
| 一覧の並び順 | お気に入り登録日時降順、同日時は書籍ID降順。開発側の実装案 | FavoriteController、FavoriteIndexTest |
| 一覧解除後の戻り先 | H043はページ保持不要。同一オリジンのRefererが/favoritesの場合、固定の一覧URLへ戻す実装案 | FavoriteController、FavoriteRedirectTest |
| Refererなし・対象外 | 固定の対象書籍詳細へ戻す。認証・操作対象の決定には使わない | 同上 |
| 同時操作 | ユーザー行ロックとトランザクションを採用。一意制約も維持 | 両トグルController。並行実行の実証は未実施 |
| 成功通知 | 新規フラッシュは追加しない実装案 | 両トグルController |
| DB障害 | H053に沿う500案を採用。H056の質問J58は505、遷移先I58・案K58は500で不一致が残る | 両TransactionTest、確認事項 |
| 今回のテスト補強 | 空のfavoritesだけでは他データ保持を検証できないため、不存在要求前後の全行を比較 | FavoriteToggleTest |

H056の505表記を訂正済みとは扱わない。

## 実行結果

| コマンド | 結果 |
|---|---|
| ./vendor/bin/sail pint --dirty | 整形済み |
| ./vendor/bin/sail artisan test --filter=DatabaseConnectionTest | 1件成功・6アサーション |
| ./vendor/bin/sail artisan test --filter=FavoriteToggleTest | 5件成功・36アサーション |
| ./vendor/bin/sail artisan test | 228件成功・1件保留・2446アサーション |
| git diff --check | 指摘なし |

保留1件はExampleTestのトップページ描画確認。
全件成功とは記録しない。

## 保留と再確認先

| 未確認事項 | 再確認の時期・対象 |
|---|---|
| ページ全体の描画・ボタン・リンク・ゲスト導線 | ranking.index・genres.index実装後、#20/#21で確認 |
| 本人一覧の画面表示・詳細リンク・ページリンク・0件表示 | 同上。#19の完了条件を再確認 |
| 一覧解除時のReferer送信と一覧への遷移 | 実ブラウザーで確認。#17/#20で追跡 |
| 同時リクエストによる行ロックの実動作 | #21の未確認事項として追跡。必要性と検証方法を整理 |
| H056の505表記 | 次回の仕様確認で誤記か確認 |
| 最新資料・追加回答の差分 | 新しい資料・回答を取得した時点で再照合 |

上記の保留事項を解消または対応先へ明示的に引き継ぐまで、
#17〜#21とマイルストーン6を全面完了とは扱わない。

## Issue #36での再確認（過去の保留事項の更新）

上記の2026-11-12時点の履歴は保持する。現在の結果と未確認事項は[MS8検証記録](ms8-verification.md)を参照。

- トップページ描画の保留はIssue #35で解除。今回の全体テストは284件・2937アサーション成功。
- InteractionScreenIntegrationTestで、本人のお気に入りだけの10件表示・ページリンク・書籍詳細リンク・0件表示・他会員の保持を確認。
- 同テストで、いいね追加解除後のHTML上の登録状態・件数を確認。手動クリックによるいいね操作とは区別する。
- ブラウザーで、お気に入り一覧のハートから解除した後に/favoritesへ戻り、対象カードが消えることを確認。詳細での解除状態と再登録も確認。
- 再登録によりfavorites.created_atが新しくなり、対象書籍が先頭へ移ることを確認。登録状態の復元と表示位置の復元は異なる。並び順は既存の設計案として扱う。
- 同時要求によるロックの実証、H056の表記、カテゴリ全体の未確認事項まで解消したとは扱わない。後続の再確認先はMS8検証記録へ集約する。

## Issue #21での残作業確認（2026-11-19）

### 対象と検証した版
- 関連Issue：#17〜#21。
- 作業ブランチ：feature/issue-21-favorites-likes-completion。
- 検証したコミット：717bc6e。
- 今回は既存実装の再検証と記録更新。アプリケーションコード・提供Blade・テストの変更なし。
- 記録更新後のコミットIDとPRは、コミット後にPR本文へ記録する。

### 自動検証
- DatabaseConnectionTest：1件・6アサーション成功。MySQLのtesting DBへの接続を確認。
- MS6関連・画面統合・Model関連・DB制約：64件・726アサーション成功。
- 全体テスト：449件・5,046アサーション成功。失敗・保留なし。
- MS6対象Controller 2ファイル・テスト10ファイルのPint --test：成功。
- 記録更新前のgit diff --check：指摘なし。
- 全体Pint --test：184ファイル中13ファイルで整形違反。全体の整形確認は未完了。
- 指摘対象：Kernel、RedirectIfAuthenticated、bootstrap/app.php、config/auth.php、
  config/sanctum.php、UserFactory、基本業務Migration 6本、ReviewLikeSeeder。
- 今回は自動修正せず、全体の整形是正と再確認をMS10へ引き継ぐ。

### ブラウザー確認
- 根拠：ユーザー共有の操作結果・画像。
- 山田太郎で「吾輩は猫である」（/books/1）の本人レビューを操作。
- 自己いいねを0→1→0→1→0と切り替え、登録状態と件数の表示が対応することを確認。
- 最後に操作前の未登録・0件へ復元。他のレビューの表示・件数は1件・2件のまま。
- ゲストでも書籍詳細を閲覧でき、お気に入り・いいねの両リンクから/loginへ移動。
- ゲスト表示のいいね数は0件・1件・2件を維持し、本人の登録済み表示は出ない。
- お気に入り一覧から解除後の/favorites復帰と再登録は、MS8の実操作確認を引き継ぐ。
  今回再実施したとは扱わない。

### Issueごとの完了根拠
- #17：FavoriteToggleTest・FavoriteRedirectTest等とMS8の実操作で、追加解除・戻り先を確認。
- #18：ReviewLikeToggleTest等と今回の自己いいね実操作で、切り替え・件数を確認。
- #19：FavoriteIndexTest・InteractionScreenIntegrationTestとMS8記録で、本人一覧・ページ・リンク・0件表示を確認。
- #20：BookInteractionDataTest・InteractionScreenIntegrationTest、MS8と今回の実操作で、登録状態・件数・ゲスト導線を確認。
- #21：認証・ID偽装・404・CSRF・ロールバック・関連保持を含む既存テストと全体回帰の成功を確認。
- GitHubの完了条件更新・Close、PRマージは、この追記時点では未実施。

### 未確認事項と引継ぎ
- 同時リクエストによる行ロックの実証は未実施。MS10で要件上の必要性と検証範囲を整理する。
- H056の505/500表記は未解決。500は既存の実装案であり、PMによる訂正済みとは扱わない。
- 全体Pintの13ファイルはMS10で是正・再確認し、MS6対象ファイルの成功と区別する。
- 最新の正式要件・追加PM回答との差分は、新しい資料を取得した時点で再照合する。
- Excelへの転記、カバレッジ計測、新規環境でのREADME再現検証は今回の完了範囲に含めない。
