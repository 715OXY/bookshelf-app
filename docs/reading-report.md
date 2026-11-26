# マイ読書レポート：取得基盤の設計・検証記録

> 1〜12節はIssue #84の資料作成時点の履歴として保持する。そこに記載された未実装・未コミット・保留状態、行番号、ハッシュは当時のもの。
> Issue #85の現行実装・承認済み暫定仕様・最終検証記録は13〜19節を参照する。過去の検証数値を現行値で上書きしない。

## 1. 資料概要

- 対象マイルストーン：14. ★ マイ読書レポート
- 対象Issue：[Issue #84「［マイ読書レポート］集計仕様整理・認証基盤の実装」](https://github.com/715OXY/bookshelf-app/issues/84)
- 作成日：2026-11-26
- リポジトリ：715OXY/bookshelf-app
- 作業ブランチ：feature/issue-84-reading-report-foundation
- 検証基準コミット：ecfdace05495b6dfd7afdbf58f9fd4aacdca0cac
- 開発標準：Laravel AI駆動開発標準 v1.0 — Lite、AGENTS.md v1.2
- 検証対象：基準コミットに取得基盤・テストの未追跡3ファイルを加えた未コミット状態。本資料はその検証後に追加した記録。
- 適用範囲：本人限定取得基盤の設計・検証と、未確定集計・画面接続の引継ぎ。提出コミット、Issue #84のPR・マージ結果は未確定。

実行検証の数値は、ユーザーがWSLで実行しChatGPTが共有ログをレビューしたものとして、STEP 08依頼で提示された記録に基づく。今回Codexがテスト等を新たに実行・計測した結果ではない。

## 2. 正式要件と参照資料

### 正式要件

原本：最新版_奥島 聖吾さん_新模擬案件_Bookshelf_要件シート_20261121.xlsx。プロジェクト資料保存先の原本を読み取り参照し、開発リポジトリへ複製・変更していない。

| シート名・セル範囲 | 確認した要件 |
|---|---|
| シート3 開発プロセス E17:E20 | Laravel標準命名・PSR-12、Pint、Eloquentと先読み、ControllerとService等の責務 |
| 同 E28:E30 | 型宣言、主要メソッドのPHPDoc、Collection活用。特にレポートで徹底 |
| シート5 画面設計 D21:I21 | PG14、GET /reports、認証必須、ログインユーザーの読書統計 |
| シート7 機能要件 C48:I48 | 認証時に4統計、未認証時は/login。読了冊数はユニーク書籍数。高評価書籍・ジャンル別傾向は各最大5件、詳細リンク付き |
| シート9 シーディング要件 D11:F12 | 書籍登録者のランダム化、評価1〜5・指定の評価別日本語コメント、各書籍2〜4レビュー、ランダム投稿者 |
| シート11 データ要件 D35 | 同一会員・同一書籍への複数レビューを許可 |
| シート12 テーブル仕様書 E43:J46 | レビュー投稿者・対象書籍の外部キー、unsigned tinyintの評価と1〜5のCHECK制約、コメントNULL許可 |

### PM回答・既存実装・承認方針の区分

| 区分 | 根拠と扱い |
|---|---|
| PM明示回答 | ヒアリングシートH008（行10 J:L）は複数レビュー可。K列の1書籍1件案を回答として採用しない |
| PM明示回答 | H016（行18 J:L）は安全な同一アプリの元GETへのログイン後復帰を承認 |
| PM明示回答 | H057・H058（行59・60 J:P）は基本テーブル設計・関連削除方針を確認。レポート集計の個別承認ではない |
| 他機能固有の回答 | H069・H070（行70・71 J:L）はランキングの複数レビュー計上・第2ソート。レポートへ自動適用しない |
| 既存実装 | Model・Migration・提供Blade・認証機構と今回の取得Service／テストを直接確認。詳細は4・6・7節 |
| ユーザー承認 | 2026-11-26の方針A：取得基盤先行・画面公開延期。テスト責務分割も同日に承認 |
| 未確定の開発案 | 5節の集計・副ソート・丸め案。ユーザーによるヒアリング文案承認とPM仕様承認を区別する |
| 前PJ参考情報 | クラス200行・関数80行規約。Bookshelfへの正式適用範囲は未確認。8節参照 |

既存資料は[レビュー機能記録](review-validation.md)、[Advanced Blade引継ぎ](ms11-advanced-blade-handover.md)、[MS12統合検証](ms12-integration-verification.md)、[MS10最終検証](ms10-final-verification.md)を参照した。過去の未実装・検証数値は当時の履歴として扱う。

今回確認した原本ではH091〜H095のB92:B96に番号のみ存在し、質問・回答本文は空欄だった。原本より後の回答資料は未取得。番号の存在だけで質問登録・回答済みとは判定しない。

## 3. 実装範囲と段階実装方針

Issue #84は仕様整理・本人限定レビュー取得・先読み・Collection提供・対応テストを対象とする。最新版Issue本文も画面公開を後続へ移管した方針Aと一致している。

未完成の統計を0値・空リストで代用すると、提供Blade上で実際の対象なしと区別できない。そのためController・公開ルートへ暫定データを接続せず、4種類の統計が揃うIssue #86で接続する。提供Blade・ナビゲーションは変更しない。

| Issue（2026-11-26に本文を確認、いずれもopen） | 責務 |
|---|---|
| [#84：集計仕様整理・認証基盤の実装](https://github.com/715OXY/bookshelf-app/issues/84) | 仕様整理・本人限定取得基盤 |
| [#85：基本サマリー・評価分布の実装](https://github.com/715OXY/bookshelf-app/issues/85) | 画面公開前の基本統計・評価分布 |
| [#86：高評価書籍・ジャンル別評価傾向TOP5の実装](https://github.com/715OXY/bookshelf-app/issues/86) | TOP5と、4統計完成後のController・認証ルート・Blade接続 |
| [#87：応用Seeder対応・統合検証・資料更新](https://github.com/715OXY/bookshelf-app/issues/87) | 応用Seeder、マイルストーン全体の統合検証・資料更新 |

#85の目的等には画面表示の記述もあるが、作業内容で画面公開前の実装・検証と明示され、#86に公開時期が記載されている。LMS側の責務変更の反映状況は未確認。Issue #84の基盤完成をマイルストーン全体の完成とは扱わない。

## 4. 本人限定レビュー取得基盤の設計

[ReadingReportService](../app/Services/ReadingReportService.php)（11〜22行）の実装契約：

- クラス：App\Services\ReadingReportService
- メソッド：getReviewsFor(User $user): Collection
- 戻り値：Illuminate\Database\Eloquent\Collection、PHPDocはCollection<int, Review>
- 呼出側が認証済みと確認した保存済みUserを渡す。Service単体ではHTTP認証を保証しない。
- $user->reviews()->with('book.genres')->get()で、投稿者をDBクエリ段階で限定する。
- 書籍登録者・出版日・評価で絞り込まず、重複排除・集約・件数制限を行わない。
- 本人が他会員登録本へ投稿したレビューを含み、本人登録本への他会員投稿は含まない。
- 同じ本への複数レビュー、ジャンル未所属書籍を保持する。0件時は空のEloquent Collection。
- 取得順序を保証しない。Request・Auth・HTTP応答・ダミー集計は持ち込まない。

関連は[User::reviews](../app/Models/User.php)（58〜60行）、[Review::book](../app/Models/Review.php)（34〜36行）、[Book::genres](../app/Models/Book.php)（44〜51行）、[Genre::books](../app/Models/Genre.php)（19〜26行）を使用する。

[reviews Migration](../database/migrations/2026_11_06_044525_create_reviews_table.php)（15〜30行）は会員・書籍への外部キー、評価1〜5のCHECKを持ち、会員・書籍の複合UNIQUEは持たない。[book_genres Migration](../database/migrations/2026_11_06_044526_create_book_genres_table.php)（14〜24行）は多対多と組合せUNIQUEを定義する。

集計前のReview Modelと先読み済み関連をCollectionとして提供する。後続では承認仕様に応じてmap・groupBy・flatMap等を使う。今回、4統計の集計や$statsの生成は実装していない。

後続Controllerは[既存auth:webグループ](../routes/web.php)（22〜82行）と[Request::user()の既存例](../app/Http/Controllers/FavoriteController.php)（61行）に整合させ、外部入力のuser_idで対象会員を選ばない。通常HTMLの未認証時は[Authenticate](../app/Http/Middleware/Authenticate.php)（13〜15行）による/login誘導を利用する。これらは未実装のレポートControllerへの引継ぎ条件であり、今回のHTTP認証検証済み証拠ではない。

## 5. 集計仕様とPM確認事項

正式要件は基本サマリー・評価分布・高評価書籍TOP5・ジャンル別評価傾向TOP5を要求する。以下の案は未実装であり、PM明示回答を確認するまで正式仕様として確定しない。

| 確認項目 | 正式要件／確認済みの表示制約 | PM確認用の提案・未確定範囲 |
|---|---|---|
| 基本統計・評価分布 | 読了冊数はユニーク書籍数。総レビュー数・平均・星1〜5の分布を表示 | 投稿レビューを全件個別計上し、平均も本人の全レビューから算出。読了書籍だけ重複排除 |
| 高評価書籍TOP5 | 4星以上、評価降順、最大5件、書籍詳細リンク | 本人の評価対象本を重複表示せず、同一書籍の最高評価を代表値とし、その値で4以上を判定 |
| ジャンル別傾向TOP5 | ジャンル別平均と件数、最大5件、詳細リンク | 本人レビューを各所属ジャンルへ1件ずつ計上。平均はそのレビュー単位で算出。未所属はこの統計だけ対象外 |
| 同順位・副ソート | 各TOP5は最大5件。Bladeの順位は添字+1 | 書籍：代表評価降順→書籍作成日時降順→書籍ID降順。ジャンル：丸め前平均降順→件数降順→ジャンルID昇順。表示は連番 |
| 丸め・0件 | Bladeの表示条件は6節の事実として確認 | 中間計算は丸めず表示を小数第1位へ。0件は件数・冊数0、分布5区分すべて0、TOP5は空。平均の内部値0.0／null等は保留 |

各ジャンルへ計上する案では、ジャンル件数の合計が総レビュー数を上回り得る。これは承認前の案の性質であり、確定した集計挙動ではない。対象が5件未満なら実件数を表示する案も後続で確認する。

ヒアリング登録状況は次のように区別する。

| 確認事項 | 状況・根拠 |
|---|---|
| 基本統計・評価分布 | H091の登録はユーザー共有の先行記録に基づく。今回原本の質問・回答本文は空欄 |
| 高評価書籍の代表評価・抽出 | H092の登録は同上。回答未確認 |
| ジャンル別計上単位 | H093の登録は同上。回答未確認 |
| 同順位・副ソート | 対応する登録番号・登録状況は未確認 |
| 丸め・0件 | 対応する登録番号・登録状況は未確認 |

原本にH094・H095という番号はあるが、上記2件への割当・登録完了を推測しない。新しいPM明示回答は今回確認できていない。回答入手時は内容・根拠・従来案との差分を記録し、承認手順を経て後続コードへ反映する。

## 6. 提供Bladeのデータ契約

[reports/index.blade.php](../resources/views/reports/index.blade.php)を参照した後続実装向けの契約整理。現在のServiceはこの構造を生成・返却しない。

| キー | 型・内容 | 参照行・表示上の条件 |
|---|---|---|
| $stats | 必須キーを持つ配列互換構造。実装案は連想配列 | キー欠落の代替処理なし |
| summary | total_reviews・books_read・average_ratingを持つ配列互換構造 | 16〜29行 |
| summary.total_reviews | 非負整数 | 16行。そのまま表示 |
| summary.books_read | 非負整数 | 20行。そのまま表示 |
| summary.average_rating | 数値。0件時の内部値・型は未確定 | 25〜29行。>0ならnumber_format(..., 1)、それ以外は「-」 |
| rating_distribution | max()を呼べるCollection。キー0〜4、値は非負整数の5要素 | 43〜60行。キー+1が星数。0件区分も0で補完 |
| top_rated_books | count可能・反復可能な0始まりリスト。配列リストを推奨 | 69〜96行。各要素はid:int、title:string、author:string、rating:int |
| genre_ratings | 同上 | 106〜134行。各要素はid:int、name:string、count:int、average_rating:数値 |

書籍情報はReviewのbook、ジャンルID・名称はbook.genresから変換する。ratingは整数星を描くための表示値であり、代表評価の選択はPM回答後に確定する。ジャンル平均は小数第1位で表示される。

分布はmax() ?: 1を分母としゼロ除算を防ぐ。最頻区分が100%となる相対幅で、全レビュー比ではない。全0でも5要素を渡せば5区分を描画できるが、空Collectionではバー自体が描画されない。

TOP5のソート・重複排除・最大5件への制限はBladeが行わない。後続の集計側で整え、values()等で0始まりへ振り直す。表示順位は添字+1の連番で、同順位表示機構はない。

空リストでは高評価書籍に「4星以上の書籍がありません」、ジャンルに「ジャンルが設定された書籍のレビューがありません」を表示する。未実装を空データとして公開しない。リンク先はbooks.show・genres.show。

[共通ナビゲーション](../resources/views/layouts/navigation.blade.php)の38〜42行・127〜131行はPC／モバイルともRoute::has('reports.index')で導線を制御する。ルート追加が導線公開につながるため、#86で認証・全統計・リンクを揃えて確認する。

## 7. テスト設計とDB安全確認

| テストクラス | 件数 | 検証内容 |
|---|---:|---|
| [ReadingReportServiceTest](../tests/Feature/ReadingReportServiceTest.php) | 5 | 本人限定と登録者の区別、同一Serviceでの会員切替、同一書籍の複数レビューと評価、本人0件、書籍・複数ジャンル・未所属の先読みと内容 |
| [ReadingReportQueryEfficiencyTest](../tests/Feature/ReadingReportQueryEfficiencyTest.php) | 1 | 1冊2レビューから10冊20レビュー、各書籍2ジャンル。結果ID・投稿者・関連内容、取得SELECT数の増加なし、関連参照の追加SELECTなし |

SQL効率クラスのcreateMeasurementReviews()がFixtureを計測範囲外で生成する。measureReviewQueries()は接続のDispatcherをクローンし、QueryExecutedのSELECTを数え、取得直後と関連参照後を分ける。finallyで元のDispatcherを復元し、クエリログの有効・無効状態は変更しない。

共有ログに具体的な取得SELECT実測値はない。「件数増加で同数」「関連参照の追加0件」という合格条件と、出力されていない具体的な取得回数を区別する。固定の取得回数を測定結果として記載しない。

両クラスはRefreshDatabaseを使用し、beforeRefreshingDatabase()でtesting環境、MySQLドライバ、設定上のDB名testing、SELECT DATABASE()の実接続先testingを確認する。フックの失敗時はDB再構築へ進まない既存構成を維持する。

[phpunit.xml](../phpunit.xml)（21〜27行）の強制指定、[CreatesApplication](../tests/CreatesApplication.php)（19〜29行）の実接続先確認、[DatabaseConnectionTest](../tests/Feature/DatabaseConnectionTest.php)と併用する。DB安全確認テストを先行する段階検証を維持する。Service直接呼出しのテストをHTTP認証・画面描画の検証と混同しない。

## 8. テストクラス分割の判断記録

2026-11-26、STEP 07-Gのレビューを受け、ユーザーが取得機能とSQL効率の責務分離を承認した。STEP 07-HでSRP・保守性・自主的な行数目標を理由に分割した。正式な規約違反への修正ではない。

前PJのクラス200行・関数80行規約は参考情報。[MS10最終検証記録](ms10-final-verification.md)（178〜180行）もBookshelfへの正式適用を確定していない。

| 対象 | ファイル物理行数 | クラス宣言範囲 | クラス本体 | 最長メソッド（PHPDoc込み） | テスト数 |
|---|---:|---|---:|---:|---:|
| 分割前ServiceTest | 307 | 16〜307：292行 | 289 | 62 | 6 |
| 分割後ServiceTest | 192 | 15〜192：178行 | 175 | 62 | 5 |
| QueryEfficiencyTest | 149 | 16〜149：134行 | 131 | 51 | 1 |

物理行には空行・PHPDocを含む。クラス宣言範囲はclass宣言から最後の閉じ括弧まで両端を含み、クラス本体は外側の波括弧の行を除く。メソッドは宣言から閉じ括弧までを数え、直前PHPDoc込みの長さも確認した。規約自体の計数方法は未指定。

既存6テスト本文、Fixture、アサーション、期待結果、2つのSQL計測用ヘルパーとPHPDocは無変更で移管した。新クラスにも同一のDB安全確認を配置し、元クラスの不要なQueryExecuted importだけ削除。Service本体は不変。両クラスは自主目標の宣言範囲200行以内・メソッド80行以内を満たす。

## 9. 実行検証結果

検証日：2026-11-26。以下はユーザー実行・ChatGPTログレビューとして共有された記録であり、今回Codexの再実行結果ではない。検証版は1節の未コミット状態。今回の資料追加後もPHPコードを変更していない。

### 9-1. 分割前の検証履歴

| 項目 | 共有結果 |
|---|---|
| DB安全確認 | 1 passed、6 assertions、0.46秒 |
| 対象テスト | 6 passed、231 assertions、2.12秒 |
| 関連回帰 | 4クラス・18 passed、255 assertions、3.38秒 |
| 全体回帰 | 880件、879正常成功・Deprecation 1件、9954 assertions、43.37秒、失敗・エラー0 |
| Pint | 個別2ファイル・全体222ファイルPASS、自動整形なし |
| レポートルート | URI・名前付きルートとも未登録 |

上記実行検証の各終了コードは0。分割後の結果とは区別して保持する。

### 9-2. 分割後の最終検証（STEP 07-I）

| 段階 | 共有結果 | 終了コード |
|---|---|---|
| 07-I-1 | DatabaseConnectionTest：1 passed、6 assertions、0.35秒 | 0 |
| 07-I-2 | ServiceTest：5 passed、QueryEfficiencyTest：1 passed、計6 passed、231 assertions、2.71秒 | 0 |
| 07-I-3 | 全体880件、879正常成功・Deprecation 1件、9954 assertions、42.44秒、失敗・エラー・スキップ0 | 0 |
| 07-I-4 | 個別3ファイル・全体223ファイルPint PASS、スタイル違反0、自動整形なし | 個別・全体とも0 |
| 07-I-5 | 指定ブランチ・HEAD一致、新規3ファイルのみ、Serviceハッシュ一致、追跡済み差分なし | 下記の読取確認と区別 |

07-I-5ではService 24行・ServiceTest 192行・QueryEfficiencyTest 149行、合計365行を確認。新規3ファイルの全内容・個別差分を確認し、検証前後の変更ファイル一覧は一致した。提供Blade・ルート定義の差分なし。GET /reports・reports.indexは未登録で、route:listの各終了コードは0だった。

git diff --check HEADは終了コード0。diff --no-indexとdiff --no-index --checkは終了コード1であり、差分の存在と診断出力を併せて確認した結果、空白不備の指摘は認められなかった。すべてのコマンドが終了コード0だったとは扱わない。

### 9-3. ハッシュと資料作成時の保全確認

ServiceのSHA-256はSTEP 07-Hで記録し、07-I-5で一致を確認したとの共有記録に加え、STEP 08でも直接比較した。

| 対象 | SHA-256 |
|---|---|
| app/Services/ReadingReportService.php | 2a95e8d7373c96e64c06de34ab3b7c37425fafe84ee0b111af7b17ce61b19ace |
| tests/Feature/ReadingReportServiceTest.php | 829c5d6944d7b9e127b424dfc63958636e6ca6a2cfc7d857a7ae4b6e409f9279 |
| tests/Feature/ReadingReportQueryEfficiencyTest.php | 87c454254e7248348e42e9f0a8eb106e6e04fed73a9aa20a87bb55fb52af7394 |

STEP 08の作成開始・終了時に実装済み3ファイルのハッシュ一致を確認し、本資料だけを新規作成した。追跡済みファイルの差分なし、新規Markdownの個別差分・空白診断を確認した。テスト・Pint・route:list等は再実行していない。

### 9-4. 分割前後の比較と限界

対象テスト6件・231 assertions、全体880件・9954 assertionsは一致し、失敗・エラー0を維持。Pint対象はクラス分割により222から223へ1ファイル増えた。

実行時間は単発計測の参考値であり、差から性能改善・悪化を断定しない。個別・関連回帰・全体回帰は対象が重複するため件数・assertionsを合算しない。新しいカバレッジ値や具体的な取得SELECT数は今回の記録に含まれない。

## 10. 既知事象・保留事項

### 10-1. Deprecation

検出1件。共有記録の対象はTests\Unit\Models\BookTestの「登録者との多対一関連が定義されている」、Laravel\Sanctum\HasApiTokens。AGENTS.md v1.2の既知事象と整合する。

詳細メッセージは共有ログで省略されているため、発生箇所の完全一致を今回独立して確認したとは扱わない。依存関係・vendorは変更していない。

### 10-2. 行数規約・PM回答

前PJ規約のBookshelf、特にテストクラスへの正式適用範囲は未確認。今回の新規実装は自主目標としてクラス200行・メソッド80行以内。集計に関する5件のPM確認は5節の状態を継続する。

### 10-3. Issue #79との統合

2026-11-26に[PR #83](https://github.com/715OXY/bookshelf-app/pull/83)を確認し、Draft・open・未マージ。headはfeature/issue-79-integration-verification、cb5c07c842043ce18f47310b211abaadbf21bc81。出版日任意化の変更は今回のブランチへ取り込んでいない。

PR本文でもH089（必須マーク）・H090／関連H077（APIの出版日省略時処理）は回答待ちとしている。基準側の出版日NOT NULLと、先行ブランチ側のNULL許可を混同しない。

統合後に出版日NULL書籍のレビュー取得・後続集計・書籍詳細リンク・統合回帰を再確認する。今回のテストでNULL出版日を挿入した、統合を検証済み、とは記録しない。

### 10-4. 後続Issue・LMS

GitHubの#84〜#87本文で責務を確認した。LMSの責務変更反映は未確認。PM回答の原本との同期、Issue #84のCommit・Push・PR・最終承認は別工程で管理する。

## 11. 後続Issueへの引継ぎ

| 移管先 | 実装・確認条件 |
|---|---|
| #85 基本サマリー・評価分布 | 取得Serviceを再利用。PM確定後の単位・平均・複数投稿を反映し、5区分と0件時データ契約を検証する |
| #86 TOP5・画面接続 | 代表評価、ジャンル単位、副ソート、丸め、最大5件・5件未満・0件を確定後に実装。4統計完成後にGET /reports・reports.index・Controllerを接続する |
| #86 認証・画面確認 | auth:web等の既存機構、未認証/login誘導、ログイン後復帰、本人統計のみの表示、詳細リンク、PC／モバイル導線、描画込みN+1を確認。提供Blade変更禁止を維持する |
| #87 応用Seeder | シート9 D11:F12に従い登録者ランダム化、評価1〜5、指定の評価別コメント、各書籍2〜4件、ランダム投稿者を実装。既存Seeder・テスト・統計反映を照合する |
| #87 統合検証・資料 | レビュー追加・更新・削除後の再集計、認証・会員間分離・4統計・境界・詳細リンク、全体回帰・SQL効率・Pint・画面を確認し、PM回答と検証版を記録する |

Seederのランダム生成と、集計の境界条件を固定するテストデータは区別する。正式な生成要件を実装案で置換しない。Issue #79統合後の再確認は10-3節の条件を引き継ぐ。

## 12. Issue #84の完了状況

| 区分 | 状況 |
|---|---|
| 調査・設計 | 正式要件・既存実装の調査、PM確認事項整理、段階実装方針・Blade契約整理を実施済み |
| 取得基盤・テスト | 本人限定取得、先読み、取得機能・SQL効率テスト、責務分割を実施済み |
| 実行検証 | ユーザー実行記録で分割後の対象・全体回帰、Pint、ルート未登録、差分・ハッシュ確認を完了。証拠範囲は9節 |
| 資料 | STEP 08で本資料を作成。資料を含む最終レビュー・Issue完了承認はユーザー判断待ち |
| 未確定 | PM未確定集計、行数規約適用範囲、LMS反映状況 |
| 後続実装・検証 | 4統計、レポート固有のHTTP認証接続・Controller・画面、応用Seeder、Issue #79統合後確認、マイルストーン全体の統合検証 |
| Git工程 | ステージング・Commit・Push・Issue #84のPR作成・Mergeは未実施 |

Issue #84の承認済み範囲と、マイルストーン全体の完成・提出準備を区別する。既存認証機能そのものが未実装という意味ではない。ユーザー承認前に次のGit工程や後続実装へ進まない。

## 13. Issue #85の更新概要・根拠・承認区分

- 対象：[Issue #85「基本サマリー・評価分布の実装」](https://github.com/715OXY/bookshelf-app/issues/85)。更新日：2026-11-26。
- 作業ブランチ：feature/issue-85-reading-report-summary。HEAD：2bc85a8fd03cf979a466c3b2f63e12d4cbaf290f。
- 検証対象は上記HEADにService変更・新規テスト3ファイルを加えた未コミット状態。本資料の更新はその検証後に行う。
- 先行Issue #84・PR #88は、Issue #85の作業指示でClosed・Mergedと共有された。12節の未実施記録はIssue #84資料作成時の履歴として保持する。

正式要件の参照基準は2節の20261121版要件シート。シート3 E17:E20・E28:E30、シート5 D21:I21、シート7 C48:I48（読了冊数はH48）、シート9 D11:F12、シート11 D35、シート12 E43:J46をSTEP 03で確認した記録に基づく。今回の資料更新では原本を再確認していない。資料保存先の原本と開発リポジトリを区別する。

H008・H016・H057・H058は2節で確認された範囲に限定する。ランキングのH069・H070をレポートの正式仕様へ自動適用しない。H091〜H093の最新PM回答は未確認であり、「未回答」「承認済み」と断定しない。H094・H095の内容や登録状況も番号から推測しない。

2026-11-26のユーザー承認：STEP 04の方針Bにより、PM回答の最新状態は未確認のまま、承認済み暫定仕様で先行設計・実装する。STEP 05のService拡張案Aに、集計時の全SQL増分0件の必須検証と、入力・認証責務のPHPDoc明記を加えた詳細設計を採用した。Issue #84の取得基盤先行・画面公開延期方針は継続する。

PMの正式承認とユーザーの暫定仕様承認は別である。PM回答取得後は出典・差分・影響を記録し、独断で仕様を書き換えず、必要な修正・再検証を行う。正式仕様適合の最終判定およびIssue Closeは整合確認まで保留する。

## 14. Issue #85の集計仕様・管理ID

| ID | 実装する仕様 | 根拠・区分 |
|---|---|---|
| S01 | 指定会員本人の全レビュー | 既存本人限定取得契約・認証会員の選定は呼出側 |
| S02 | 同一書籍への複数レビューも総数へ個別計上 | ユーザー承認済み暫定仕様、最新PM回答未確認 |
| S03 | book_idの重複を排除した読了冊数 | 正式要件：シート7 H48 |
| S04 | 本人の全レビューの算術平均 | ユーザー承認済み暫定仕様、最新PM回答未確認 |
| S05 | 平均は常にfloat | ユーザー承認済み実装方針・内部データ契約 |
| S06 | 集計内で中間丸めを行わない | ユーザー承認済み暫定仕様、最新PM回答未確認 |
| S07 | 0件平均は0.0 | 承認済み内部代替値。平均未定義を表し、有効な評価点ではない |
| S08 | 各レビューを評価別件数へ個別計上 | ユーザー承認済み暫定仕様、最新PM回答未確認 |
| S09 | 星1〜5の5区分を保持し欠損を整数0で補完 | 表示要件・提供Bladeとのデータ契約 |
| S10 | 整数キー0〜4のSupport Collection | 提供Bladeとのデータ契約 |
| S11 | 分布合計とレビュー総数を一致させる | 採用した計上単位間の整合条件 |
| S12 | #85では画面未公開、#86でTOP5と合わせて接続 | ユーザー承認済み段階実装方針 |

同一会員・同一書籍への複数レビュー許可は正式要件とH008に基づく。許可される投稿と、それを各統計でどう計上するかの承認区分を混同しない。

## 15. Issue #85のService設計・部分集計契約

[ReadingReportService](../app/Services/ReadingReportService.php)を今回直接確認した。既存getReviewsFor(User $user): Collection（19〜24行）は、本人レビューとbook.genresを取得する実装・PHPDoc・契約を維持する。

| メソッド | 可視性・戻り値 | 責務・現行行番号 |
|---|---|---|
| getReviewsFor(User $user) | public、Eloquent Collection<int, Review> | 本人限定取得・先読み。19〜24行 |
| getBasicStats(Collection $reviews) | public、array | 基本サマリーと分布の部分結果。26〜44行にPHPDoc・実装 |
| buildSummary(Collection $reviews) | private、array | count、book_idのpluck・unique・count、avgとfloat cast。46〜60行 |
| buildRatingDistribution(Collection $reviews) | private、SupportCollection<int, int> | ratingのpluck・countBy、星1〜5をmapし欠損0、values。62〜75行 |

入力はgetReviewsFor()で取得した指定会員本人の全レビュー。対象会員の選定と本人限定取得は呼出側・取得基盤の責務であり、getBasicStats()はHTTP認証・会員の再選別を行わない。会員混在の入力は契約違反である。

取得済みのbook_id・ratingだけを使い、レビューの再取得、DB再集計、遅延読み込みを行わない。入力Collection・Modelを変更せず、Serviceに会員別集計状態を保持しない。出版日を参照しない。評価1〜5のDB制約とReviewのinteger castを前提とし、無効値の黙示除外・補正を加えない。

戻り値は以下の部分集計契約であり、TOP5のプレースホルダーや完全な$statsを返さない。

```php
array{
    summary: array{
        total_reviews: int,
        books_read: int,
        average_rating: float
    },
    rating_distribution: SupportCollection<int, int>
}
```

本人が書籍Aへ評価5・1、書籍Bへ評価4を投稿した場合、total_reviews=3、books_read=2、average_rating=10 / 3、分布=[1, 0, 0, 1, 1]。他会員による書籍Bへの評価2は取得基盤で対象外となる。

0件時はtotal_reviews=0、books_read=0、average_rating=0.0、分布=[0, 0, 0, 0, 0]。平均は整数相当でもfloat、分布のキーは整数0〜4、値は整数5要素とする。

平均の小数第1位整形・0件時の「-」表示・分布バー計算は6節の提供Bladeの責務。今回のIssueでは画面へ接続せず、画面描画の実行検証も行っていない。

## 16. Issue #85のテスト設計・SQL計測・DB安全性

以下3ファイルを今回直接確認した。新規テストは合計11件。

| クラス・ファイル | 件数 | 検証責務 |
|---|---:|---|
| [ReadingReportBasicStatsTest](../tests/Unit/Services/ReadingReportBasicStatsTest.php) | 7 | 総数と冊数、丸め防止、float、星別順序・整数件数、欠損0と合計一致、0件、入力Collection・Modelの非破壊性 |
| [ReadingReportBasicStatsIntegrationTest](../tests/Feature/ReadingReportBasicStatsIntegrationTest.php) | 3 | 本人限定と登録者・ジャンル所属の区別、同一Serviceでの会員切替、追加・更新・削除後の再取得と再集計 |
| [ReadingReportBasicStatsQueryEfficiencyTest](../tests/Feature/ReadingReportBasicStatsQueryEfficiencyTest.php) | 1 | 1冊2レビュー→10冊20レビュー、取得のみと取得＋集計の比較、集計時のSELECT・全SQL増分0、件数増加で取得SELECT数増加なし |

UnitはPHPUnit\Framework\TestCaseを直接継承し、new ReviewとEloquent Collectionを使う。DB・Factory・Laravelアプリ起動に依存しない。非連続キー、モデル同一性、属性・original・関連状態を比較し、privateメソッドを直接呼び出さない。平均のfloat型検証と誤差1.0E-12の数値比較を分ける。S02・S04・S06・S08の期待値は暫定仕様への適合を検証する。

Integrationは固定評価・明示した投稿者と書籍を使う。BookFactoryの自動ジャンル付与を未所属Fixtureで解除する。変更後の再計算は[5]→[5,1]→[3,1]→[1]→[]の各段階で再取得する。HTTPによるレビュー操作・認証・表示までの検証は後続Issueへ引き継ぐ。

SQL効率テストのmeasureQueries()（100〜146行）は、特定したDB接続のDispatcherをcloneし、QueryExecutedで全SQLとSELECTを区別して数える。User・Review取得クエリ・Book・Genreの接続と一致することを確認する。Fixture生成・安全確認SQLは計測外で、取得直後（127〜128行）と集計直後（129〜131行）を区切る。

少数・増加後とも、取得SELECT数>0、集計追加SELECT=0、集計追加全SQL=0、取得＋集計のSELECT・全SQL数が取得のみと一致することを必須検証する。結果ID・投稿者・集計内容も照合する。取得SQL数の絶対値は固定せず、具体的な実測回数を推測しない。

計測対象はテストで特定した接続である。任意の別DB接続を含む全アプリケーションのSQLを監視したという保証ではない。finally（143〜145行）で元のDispatcherを復元し、クエリログの状態を変更しない。

新規Feature 2クラスはRefreshDatabaseを使用し、beforeRefreshingDatabase()でtesting環境、MySQL、設定上のDB名testing、SELECT DATABASE()による実DB名testingを確認する。既存フックと同様、失敗時にDB再構築へ進まない。phpunit.xml・CreatesApplication・DatabaseConnectionTest・既存テストは無変更。今回の資料更新でDB接続や安全確認SQLを実行していない。

## 17. Issue #85の品質検証記録

出典：2026-11-26のIssue #85 STEP 08作業指示でユーザーから共有された検証記録。今回Codexが再実行した結果ではなく、詳細ログを新たに独立取得したものでもない。検証版は13節の未コミットPHP成果物。本資料更新後もPHPを変更しない。

### 17-1. STEP 07-A〜Eの初回検証

| 対象 | 共有された実績 |
|---|---|
| DB安全確認 | 1 passed、6 assertions |
| 新規Unit | 7 passed、34 assertions |
| 新規Feature | 4 passed、340 assertions |
| Issue #84回帰 | 6 passed、231 assertions |
| 関連回帰 | 18 passed、255 assertions |
| PHPUnit全体 | 890 passed、1 deprecated、10328 assertions、43.30秒、終了コード0 |

### 17-2. Pint初回指摘と最小修正

STEP 07-Fは対象4ファイルにスタイル指摘2件、終了コード1。全体Pintは未実行だった。STEP 07-F-1で新規テストのphp_unit_method_casingによる命名指摘と整理した。

| 対象 | 修正前 | 修正後 |
|---|---|---|
| Unit 99行 | test_集計前後で入力Collectionとモデルを変更しない | test_集計前後で入力コレクションとモデルを変更しない |
| SQL効率 36行 | test_取得済みレビューの集計でSQLを追加せず取得集計全体でも再取得しない | test_取得済みレビューの集計で追加クエリを発行せず取得集計全体でも再取得しない |

STEP 07-F-2で各宣言1行のみ変更。共有された最終確認記録では旧名称復元SHA-256でその他の内容の一致を確認済み。CodexのF-2作業時にも修正前データとのメモリ比較で各1行以外の一致を確認した。本番実装、テスト本体、Fixture、Assertions、DB安全機構は無変更で、テスト数7・3・1、物理行数138・131・147を維持した。

### 17-3. 修正後の最終検証

| 段階 | 共有された実績 | 終了コード |
|---|---|---|
| 07-F-3 | 修正対象2ファイルPint PASS。Unit 7 passed・34 assertions、DB安全1 passed・6 assertions、新規Feature 4 passed・340 assertions。新規11テスト合計374 assertions | 各0 |
| 07-F-4 | 総数891、890 passed・1 deprecated、Failed・Errors 0、10328 assertions、41.57秒 | 0 |
| 07-F-5 | 対象4ファイルPint PASS、全体226ファイルPASS、スタイル指摘0 | 各0 |
| 07-G | route:list --path=reports・--name=reportsとも該当なし。ルート・Controller・提供Bladeの差分なし | 各0 |
| 07-H | 最終コードレビューR01〜R15の15項目PASS。変更4ファイル、追加468行・削除0行、総物理行数492。既存コード・DB安全機構の差分なし。Diff Check・新規3ファイル個別空白診断に指摘なし、ステージ済み変更なし、成果物・既存資料・AGENTS.md保全 | 個別の未提示値は補完しない |

上記共有記録に基づきSTEP 07の技術的品質検証は完了と記録する。資料更新後の最終レビュー、正式仕様適合、Issue完了承認とは区別する。個別・関連・全体回帰は重複するため実績を合算しない。実行時間は単発参考値で性能差を断定しない。カバレッジや取得SELECT数の新しい数値は記録にない。

### 17-4. 既知Deprecationと保証の限界

検出1件はTests\Unit\Models\BookTestの「登録者との多対一関連が定義されている」、表示された発生元はLaravel\Sanctum\HasApiTokens…。AGENTS.md管理のSanctum 3.3.3・暗黙的nullable型宣言の既知事象と整合する。省略表示のためメッセージ全文の照合は未実施。新規Deprecation発生を示す証拠はない。依存関係・vendorは変更していない。

## 18. Issue #85成果物の保全・資料更新の検証範囲

| ファイル | 資料更新前後で保全するSHA-256 |
|---|---|
| app/Services/ReadingReportService.php | 9006f0fdc073ff5a53e58f40da86cf645345f17cc436e26ec1d4e4ae7da45951 |
| tests/Unit/Services/ReadingReportBasicStatsTest.php | 9637430d481b5a16a232e82e1e0679332dad7b8c2946bf8ed932e24149c9236e |
| tests/Feature/ReadingReportBasicStatsIntegrationTest.php | 8441e83a962b072e9001726be07be34edb3bcdc12f2e775016b0e0087d8aae89 |
| tests/Feature/ReadingReportBasicStatsQueryEfficiencyTest.php | 7f29bd22cba13d18368708af65ad5b02cf32e785d1f94a792a7eca8ccebace33 |
| tests/Feature/ReadingReportServiceTest.php | 829c5d6944d7b9e127b424dfc63958636e6ca6a2cfc7d857a7ae4b6e409f9279 |
| tests/Feature/ReadingReportQueryEfficiencyTest.php | 87c454254e7248348e42e9f0a8eb106e6e04fed73a9aa20a87bb55fb52af7394 |
| AGENTS.md | 5d34dafe35a2dde4ae67f0e8e7c581670939731269f8f785e756afff80bb2ffe |

更新前の本資料は266行、SHA-256=b578043fe3245444cdeb3e2cf4fdb69481486e1b730bd1b17e50d9bda91c55b0。今回の変更は本資料だけとし、1〜12節の本文を保持して案内と13〜19節を追加する。更新後の本資料のハッシュ・行数・差分、上記7ファイルの照合結果はSTEP 08完了報告に記録する。

今回はGit状態・差分・空白診断・ステージ済み差分・ハッシュを読み取り確認する。PHPUnit・Pint・route:list・DB操作を再実行せず、Git参照・インデックス・GitHubを変更しない。資料を含む最終レビューは別工程とする。

## 19. 残存課題・Issue #86／#87への引継ぎ

- H091〜H093および丸め・0件・TOP5の最新PM回答を確認し、暫定仕様との差分・影響を整理する。正式仕様適合とIssue Closeは整合確認まで保留。
- #86ではgetReviewsFor()で取得した同じCollectionとgetBasicStats()を再利用し、高評価書籍・ジャンル傾向TOP5を実装して4統計を統合する。認証済み本人の選定、Controller・Route・提供Blade接続、本人限定性・認証・表示・リンク・導線・描画込みSQL効率を確認する。提供Blade変更禁止を維持する。
- #87ではシート9 D11:F12に基づく発展機能用Seederと、レビュー変更後を含むマイ読書レポート全体の統合・回帰検証・資料整備を行う。
- #79／PR #83の保留事項との統合確認を残す。出版日NULL許可・API省略時処理等は10-3節の履歴を踏まえ、統合後に再確認する。今回その変更を取り込まず、NULL出版日ケースの検証済みとは扱わない。
- #79のローカルfeature/issue-79-integration-verificationおよびoriginのリモート追跡参照は、今回ともにcb5c07c842043ce18f47310b211abaadbf21bc81と読み取り確認した。fetchやGitHub最新状態の直接確認はしていない。
- Issue #85のコミット・プッシュ・PR作成・マージ・Issue Closeは未実施。PM整合確認前の管理にはDraft PRを候補とするが、今回作成しない。
- 後続Issueの設計・実装は今回開始しない。技術的品質検証完了と正式仕様・マイルストーン完成を区別し、資料レビューとユーザーの次工程指示を待つ。
