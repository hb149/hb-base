# HB ベース

WordPress案件で共通して使う土台。1つのリポジトリを直せば、入れている全サイトに更新が配れる。

- **ログインURLの統一** — `wp-login.php` を隠し、全案件で同じ入口にそろえる
- **ログイン画面の整形** — 並びと幅だけ整える（色と角丸は WordPress の既定のまま）
- **パスキーの日本語化** — Secure Passkeys の画面文言を日本語にする
- **Cloudflare Turnstile** — ログイン・パスワード再発行・ユーザー登録に人間確認を足す
- **標準プラグインのまとめ導入** — 案件ごとに手で入れて回らない
- **宣伝パネルの抑制** — 「購読してください」「レビューを書いてください」を出さない
- **管理画面のアクセス制限** — 固定IPと、管理者が許可した端末だけを通す

設定はすべて `wp-config.php` の定数。DBに秘密を置かないので、本番DBをローカルに
持ってきても鍵は付いて回らない。

---

## 入れかた

### 本番・テストサイト

1. [Releases](https://github.com/hb149/hb-base/releases) の `hb-base.zip` を
   「プラグイン → 新規追加 → プラグインのアップロード」から入れる
2. 有効化する
3. プラグイン一覧で **「自動更新を有効化」** を押す
4. `wp-config.php` に設定を書く（下記）

4 を済ませておけば、以後こちらが Release を出すだけでサイト側が勝手に上がる。
案件ごとに手で更新して回る必要はない。

### 開発中のローカル

`docker-compose.yml` でソースを直接マウントする。

```yaml
  wp:
    volumes:
      - ./wp:/var/www/html
      - ../WP共通/hb-base:/var/www/html/wp-content/plugins/hb-base:ro
```

`:ro` にしてあるのは、ローカルのWPが勝手に更新をかけて共有ソースを
上書きするのを防ぐため。ホスト側からの編集は普通にできる。

---

## 設定

`wp-config.php` の「stop editing」より上に書く。すべて任意で、
書かなければそのモジュールは何もしない。

```php
/* ---- 管理画面のアクセス制限 ---- */

// 'off'（既定）→ 'log'（遮断せず記録だけ）→ 'on'（遮断）の順に上げる
define('HB_ADMIN_GUARD', 'on');

// 許可IP。CIDR可・カンマ区切り。空でも、許可した端末からは入れる
define('HB_ADMIN_ALLOW_IPS', '203.0.113.10, 198.51.100.0/24');

// 締め出された時の逃げ道。長いランダム文字列にすること
//   https://example.com/wp-admin/?hb-unlock=<この値>
define('HB_ADMIN_GUARD_UNLOCK_KEY', 'ここに長いランダム文字列');

// 端末を信頼し続ける日数（既定 180）
define('HB_ADMIN_DEVICE_DAYS', 180);

// 遮断画面から申請フォームを出すか（既定 true）
define('HB_ADMIN_GUARD_ALLOW_REQUEST', true);

// 守る範囲。'admin+login'（既定）/ 'admin'（wp-login.php は守らない）
define('HB_ADMIN_GUARD_SCOPE', 'admin+login');

// Cloudflare などのプロキシ経由なら、その接続元のCIDRを書く。
// 書いた時だけ CF-Connecting-IP / X-Forwarded-For を読む（既定は一切読まない）
define('HB_TRUSTED_PROXIES', '');

/* ---- Cloudflare Turnstile ---- */

define('HB_TURNSTILE_SITE_KEY',   '0x4AAA...');
define('HB_TURNSTILE_SECRET_KEY', '0x4AAA...');

// 出すフォーム（既定 login, lostpassword, register）
define('HB_TURNSTILE_FORMS', 'login, lostpassword');

/* ---- ログインURL ---- */

// 未設定なら wp-login.php のまま。全案件で同じ値にする
define('HB_LOGIN_SLUG', 'hb-desk-login');

/* ---- ログイン画面 ---- */

// 既定では色も角丸も変えない。ブランドに寄せたい案件だけ書く
define('HB_LOGIN_ACCENT', '#1a4f9c');  // ボタンとフォーカス
define('HB_LOGIN_BG',     '#ffffff');  // 背景
define('HB_LOGIN_RADIUS', 8);          // 角丸(px)
define('HB_LOGIN_LOGO',   123);        // ロゴ（URL か添付ID。既定はサイトアイコン）
define('HB_LOGIN_WIDTH',  380);        // カード幅(px)
define('HB_LOGIN_STYLE',  false);      // 整形ごと止める

/* ---- Limit Login Attempts の初期設定 ---- */

// 初期値を変えたいキーだけ書く（初回投入より前に）
define('HB_LLAR_SETTINGS', ['allowed_retries' => 3, 'lockout_duration' => 1800]);
define('HB_LLAR_MANAGE', false);       // 初期値の投入ごと止める

/* ---- 標準プラグイン ---- */

// WordPress.org のスラッグを並べる。既定は下記の8本
define('HB_REQUIRED_PLUGINS', 'limit-login-attempts-reloaded, secure-passkeys, safe-svg');
define('HB_FORCE_ACTIVATE', false);        // 自動有効化をやめる
define('HB_LOCK_REQUIRED_PLUGINS', false); // 「停止」リンクを隠さない

/* ---- 管理画面の宣伝パネル ---- */

define('HB_HIDE_PROMO_NOTICES', false);                 // 抑制ごと止める
define('HB_PROMO_NOTICE_WORDS', 'review, newsletter');  // 宣伝とみなす語を差し替える

/* ---- 保守担当 ---- */

// ここに挙げた人にだけ、保守用の管理メニューを見せる
// メールアドレス・ログイン名・ユーザーIDのどれでも可。未設定なら全員クライアント扱い
define('HB_MAINTAINERS', 'you@example.com');

/* ---- 共通 ---- */

// 動かすモジュールを絞る（既定は全部）
define('HB_BASE_MODULES', 'passkey_ja, acfe_ja, login_url, login_ui, limit_login, turnstile, admin_guard');
```

ローカル検証には Cloudflare 公式の「常に成功する」テストキーが使える。

| | 値 |
|---|---|
| サイトキー | `1x00000000000000000000AA` |
| シークレット | `1x0000000000000000000000000000000AA` |

---

## 管理画面のアクセス制限

### 考えかた

固定IPを持っていないクライアントが多いので、**IPではなく端末を許可する**のを主にしている。
IP許可リストは、事務所など固定回線がある場合の補助。

通る条件は3つのうちどれか1つ。

1. 接続元IPが `HB_ADMIN_ALLOW_IPS` に入っている
2. その端末が許可されている
3. 緊急解除キー付きで開いた

守る範囲は `/wp-admin/` と `wp-login.php` だけ。公開ページには一切影響しない。
`HB_LOGIN_SLUG` でログインURLを変えている場合は、そちらも守る対象になる。
`admin-ajax.php` と `admin-post.php` は、公開側のAJAXや問い合わせフォームが
通る入口なので対象外にしている。

### 端末を許可する2つの経路

**招待する（管理者から渡す）**

設定 → HBベース → 「招待リンクを発行する」でURLを作り、許可したい端末で開いてもらう。
1回限り・30分で失効。開いた時点でその端末が許可される。

**申請を受ける（クライアントから）**

許可されていない端末で管理画面を開くと、遮断画面に申請フォームが出る。
送信すると管理者メールに通知が飛び、管理画面に「承認待ち」として並ぶ。
「許可する」を押せば、その端末から入れるようになる。

許可IPから一度入った端末も自動で覚える。事務所で設定した端末は、
その後モバイル回線に移っても使える。

### 導入の手順

いきなり `'on'` にすると自分が入れなくなることがある。

1. `'log'` にして数日動かす
2. 設定 → HBベース の「遮断した要求」に、身内のIPや端末が並んでいないか見る
3. 問題なければ `'on'` にする

### 締め出された時

| 状況 | 復帰方法 |
|---|---|
| 解除キーを設定してある | `https://example.com/wp-admin/?hb-unlock=<キー>` を開く |
| サーバーのファイルを触れる | `wp-config.php` の `HB_ADMIN_GUARD` を `'off'` に戻す |
| WP-CLIが使える | `wp plugin deactivate hb-base` |

WP-CLI・cron からのアクセスは最初から対象外なので、自動処理が止まることはない。

---

## Cloudflare Turnstile

ログインフォームに人間確認を足す。reCAPTCHA と違って利用者の操作が要らず、
Cookieも置かないので同意取得の話にならない。

**パスキーでのログインはこの検証を通らない。** 別経路（admin-ajax）で、
秘密を送らないので総当たりの的にならず、プラグイン側に回数制限もあるため、
わざと対象外にしている。守りたいのはパスワードの総当たり。

Cloudflare に問い合わせできなかったときは、本番は止め、ローカルは通す。
`HB_TURNSTILE_FAIL_OPEN` で上書きできる。

---

## ログインURL

`HB_LOGIN_SLUG` を書くと、ログイン画面が `https://example.com/<slug>/` に移る。
**全案件で同じ値を使う**（入口をそろえるのが目的なので、案件ごとに変えない）。

スラッグを決めるときは、攻撃ツールの辞書に載っている語（login, admin, signin,
secure, dashboard, backend, panel, cms, manage）と、クライアントのページ名に
なりうる語（contact, about, company, access, staff, news, works）を避ける。
数字だけの値も避けること。パーマリンクに `%year%` を含む設定だと
年別アーカイブとして解釈されることがある。

| | |
|---|---|
| 新しいURL | ログイン画面を出す |
| `wp-login.php` | 404（リダイレクトしない。したら移転先を教えることになる） |
| 未ログインで `/wp-admin/` | 404（同上） |
| `admin-ajax.php` / `admin-post.php` | そのまま通す（公開側のAJAXや問い合わせフォームの入口） |
| `wp-login.php?action=postpass` | そのまま通す（パスワード保護記事のフォーム送信先） |

生成されるURL（ログアウト、パスワード再発行、再発行メールのリンク）は
すべて新しいURLに書き換わる。`site_url` を1か所で差し替えているため。

**これは目隠しであって防御ではない。** URLを変えただけでは、入口を知っている
相手には何の意味もない。本当の防御は管理画面ガードと試行回数制限のほう。
雑な自動攻撃を減らすのと、案件間で入口をそろえるための仕掛けと考えること。

締め出されたら `wp-config.php` の `HB_LOGIN_SLUG` を消せば `wp-login.php` に戻る。

---

## ログイン画面

並び・幅・余白だけ整える。パスキーとTurnstileを足すとボタンが横に並んで
散らかるため、ラベルを項目の上に、入力とボタンを全幅に、パスキーは区切り線の下へ。

**色・角丸・影はWordPressの既定のまま。** コアのログイン画面は更新で少しずつ
変わるので、色を上書きすると追従できずに浮く。既定に乗っているほうが長持ちする。
ブランドに寄せたい案件だけ、上の定数を書けば色が付く（書かなければ一切出力しない）。

---

## 標準プラグインのまとめ導入

設定 → HBベース に一覧と導入ボタンが出る。WordPress.org から取得して有効化まで行う。

| | |
|---|---|
| `limit-login-attempts-reloaded` | ログイン試行の制限。hb-base が初期設定を投入する |
| `secure-passkeys` | パスキー。hb-base が日本語化と見出し補修を行う |
| `public-post-preview` | 未公開記事をクライアントに見せる |
| `safe-svg` | SVGアップロードの無害化 |
| `seo-simple-pack` | タイトル・説明・OGP |
| `snow-monkey-forms` | 問い合わせフォーム |
| `duplicate-post` | 記事の複製 |
| `crop-thumbnails` | サムネイルの切り抜き位置を手で決める |

### 自動有効化

入っているのに停止しているものは、管理画面を開いたときに自動で有効化する。
ダウンロードはしない（通信で管理画面が重くなるため）。未導入のものはボタンから入れる。

一覧のプラグインは、クライアントには**「停止」リンクを出さない**。
権限は変えていないので、保守担当（`HB_MAINTAINERS`）には従来どおり出る。

**保守担当が止めた場合はその判断を覚えて、自動有効化の対象から外す**
（止めた直後に戻って止められなくなるのを防ぐため）。WP-CLI からの停止も同じ扱い。
戻すときは HBベース の画面から「自動有効化に戻す」。

逆に、保守担当以外が一括操作などで止めた場合は覚えない。次に管理画面を開いた時点で有効に戻る。

**必須依存にはしていない。** WordPress 6.5 以降は `Requires Plugins:` ヘッダで
依存を宣言できるが、**依存が揃っていないと hb-base 自体が有効化できなくなる**。
hb-base は管理画面ガードやログインURLを担う土台なので、
「別のプラグインが無いから土台ごと止まる」のは本末転倒になる。

そのため依存にはせず、相手が居なければ各モジュールが黙って何もしない作りにしてある。
導入するかどうかはこの画面で選ぶ。

ACF PRO のような有料プラグインは WordPress.org に無いので扱えない。
サーバーが WordPress からの直接書き込みを許していない場合（FTP資格情報を求める構成）も
導入できないので、その旨を表示して手動導入を案内する。

---

## 宣伝パネルの抑制

プラグインを入れるたびに出る「インストールに成功しました！ニュースレターを購読」
「レビューを書いてください」の類を、全案件で同じように黙らせる。

**2段構え。**

1. **フラグ** — そのプラグイン自身が持っている「表示済み」の印を先に立てる。
   一番確実で、プラグインの作りに沿っている。分かっているものはこちら
   （`hb_admin_notice_flags` フィルタで追加できる）
2. **名前で除去** — 通知フックに登録された関数名が宣伝っぽければ外す。
   新しく入れたプラグインにも効く。HTMLを見て判断するより安全で、
   外し損ねても画面が壊れない

**消さないもの**: WordPress本体の警告（更新・メンテナンス・初期パスワード・復旧モード）、
プラグインの不具合通知、処理完了の通知、hb-base 自身の通知。
関数名で判断しているので本体の通知に巻き添えが出ない。

---

## Limit Login Attempts の初期設定

[Limit Login Attempts Reloaded](https://ja.wordpress.org/plugins/limit-login-attempts-reloaded/)
を有効にしたとき、**未設定のキーにだけ**こちらの値を書き込む。
案件ごとに設定がばらつくのと、入れ忘れ・緩めっぱなしを防ぐのが目的。

| | 初期値 |
|---|---|
| 試行回数 / ロック時間 | 4回で20分 |
| 長時間ロック | 4回ロックで24時間 |
| 試行回数のリセット | 24時間 |
| 接続元の判定 | `HB_TRUSTED_PROXIES` を書いた案件だけ転送ヘッダを見る |
| 通知 | 3回ロックされたら管理者メールへ |
| 左メニュー・ツールバー・ダッシュボード | 非表示（設定画面は「設定」の中に残る） |

管理画面の道具を隠すのは、クライアントが毎日使う画面を保守用プラグインが
占領しないため。`HB_MAINTAINERS` に登録した人が見ているあいだだけ表示に戻す。
DBの値は書き換えないので、クライアントから見た状態は変わらない。
このプラグイン自身の設定画面では上書きしない（チェックボックスが実際と
食い違い、そのまま保存すると全員に表示される設定に化けるため）。

**固定はしない。** 書き込んだあとは管理画面から自由に変更でき、こちらが
上書きし直すこともない。すでに値が入っているキーには最初から触れないので、
運用中のサイトに後から入れても設定は変わらない。

もう一度投入したいときは `wp option delete hb_llar_seeded`
（このときも、すでに値があるキーは飛ばす）。

---

## パスキーの日本語化

[Secure Passkeys](https://ja.wordpress.org/plugins/secure-passkeys/) が前提。
本家に日本語の言語パックが無く（同梱はアラビア語のみ）、ボタンもエラーも英語で出る。

翻訳ファイルを作るのではなく `gettext` フィルタで差し替えている。
プラグイン本体を触らないので更新で消えず、原文が変わった箇所は
英語のまま出るだけで壊れない。サイトのロケールが日本語でなければ何もしない。

本家に日本語パックが入ったら `src/passkey-ja.php` は丸ごと不要になる。

---

## リリースのしかた

1. `hb-base.php` の `Version:` を上げる
2. コミットしてタグを打つ

```bash
git tag v1.0.1
git push origin v1.0.1
```

GitHub Actions が `hb-base.zip` を作って Release に添付する。
各サイトは6時間以内に更新を検知する（管理画面で「更新を確認」を押せば即）。

タグとヘッダのバージョンが食い違っているとビルドが落ちるようにしてある。
片方だけ上げる事故を防ぐため。

---

## 構成

```
hb-base.php          読み込みと更新登録だけ
src/config.php       wp-config.php の定数を読む
src/ip.php           接続元IPの判定（CIDR・プロキシ）
src/maintainer.php   保守担当の見分け（HB_MAINTAINERS）
src/device.php       端末の識別と許可
src/admin-guard.php  /wp-admin と wp-login.php の門番
src/turnstile.php    Cloudflare Turnstile
src/passkey-ja.php   Secure Passkeys の日本語化
src/passkey-admin.php Secure Passkeys の管理画面に見出しを足す
src/acfe-ja.php      ACF Extended の日本語化
src/login-url.php    ログインURLの差し替え
src/login-ui.php     ログイン画面の整形
src/limit-login.php  Limit Login Attempts の初期設定
src/admin-notices.php 宣伝パネルの抑制
src/required-plugins.php 標準プラグインのまとめ導入
src/updater.php      GitHub Releases からの自動更新
src/admin-screen.php 設定 → HBベース
views/blocked.php    遮断画面
```

`src/updater.php` は他の自作プラグインにもそのまま流用できる。
本体で `hb_github_updater_register()` を1回呼ぶだけ。

案件固有の事情はここに書かないこと。案件側の `mu-plugins` に置く。
