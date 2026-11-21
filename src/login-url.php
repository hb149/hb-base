<?php
/**
 * ログインURLを差し替える
 *
 * ■ なぜ
 *   案件ごとにログインURLがばらばらだと、どこから入るのか毎回調べることになる。
 *   全案件で同じ入口にそろえる。
 *   副産物として、wp-login.php を総当たりする定型の攻撃は届かなくなる。
 *
 * ■ これは目隠しであって防御ではない
 *   URLを変えただけでは、入口を知っている相手には何の意味もない。
 *   本当の防御は管理画面ガード（固定IP＋端末許可）と試行回数制限のほう。
 *   こちらは「雑な自動攻撃を減らす」「入口をそろえる」ための仕掛けと考えること。
 *
 * ■ 設定
 *   define('HB_LOGIN_SLUG', 'office');   // https://example.com/office/ がログイン画面
 *   未設定なら何もしない（wp-login.php のまま）。
 *
 * ■ 締め出されたら
 *   wp-config.php の HB_LOGIN_SLUG を消す。すぐ wp-login.php に戻る。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 差し替え後のスラッグ。未設定・不正なら空文字。 */
function hb_login_slug(): string
{
    $slug = (string) hb_base_const('HB_LOGIN_SLUG', '');

    // URLに出せる形だけ通す。全角や空白をそのまま使われると入れなくなる。
    $slug = sanitize_title($slug);

    // WordPress が自分で使うパスは塞げない。
    $reserved = ['wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'login'];

    return in_array($slug, $reserved, true) ? '' : $slug;
}

function hb_login_url_init(): void
{
    if (hb_login_slug() === '') {
        return;
    }

    // ここはすでに plugins_loaded の最初。WordPressが行き先を決める前に判断だけ済ませ、
    // 実際の読み込みは wp_loaded まで待つ。
    hb_login_url_route();

    add_action('wp_loaded', 'hb_login_url_serve');

    // 生成されるURLをすべて差し替える。
    // login_url / lostpassword_url / logout_url などは内部で site_url を通るので、
    // ここ1か所で揃う（パスワード再発行メールのリンクも含む）。
    add_filter('site_url', 'hb_login_url_replace', 10, 2);
    add_filter('network_site_url', 'hb_login_url_replace', 10, 2);
    add_filter('wp_redirect', 'hb_login_url_replace_redirect', 10, 1);
}

/* ---------------------------------------------------------------------------
 * ルーティング
 * -------------------------------------------------------------------------*/

/**
 * この要求をログイン画面として扱うかどうかを決める。
 *
 * 実際の読み込みは wp_loaded まで待つ。wp-login.php は
 * 「自分が入口のスクリプト」である前提で書かれているので、
 * WordPressの初期化が終わる前に読み込むと途中で壊れる。
 */
function hb_login_url_route(): void
{
    global $pagenow;

    $path = hb_login_url_request_path();
    $slug = hb_login_slug();
    $file = basename($path);

    // 1. 新しいURLに来た → ログイン画面を出す
    if (trim($path, '/') === $slug) {
        hb_login_url_flag(true);

        // WordPressには別の場所を見ているように伝えておく。
        // ここで wp-login.php だと名乗ると、リライト処理が先に走って噛み合わない。
        $pagenow = 'index.php';

        return;
    }

    // 2. 元のURLに来た → 隠す
    if ($file === 'wp-login.php' && !hb_login_url_is_allowed_action()) {
        hb_login_url_flag(false, true);

        return;
    }

    // 3. 未ログインで wp-admin に来た → 隠す
    //    ここを素通りさせると、WordPressが新しいログインURLへ飛ばしてしまい
    //    URLを変えた意味がなくなる。
    if (hb_login_url_is_admin_path($path)
        && !hb_login_url_is_exempt($file)
        && !wp_validate_auth_cookie('', 'logged_in')) {
        hb_login_url_flag(false, true);
    }
}

/**
 * ルーティングの判断を持ち回る。
 *
 * @param bool|null $serve 新しいURLとしてログイン画面を出すか
 * @param bool|null $hide  見つからなかったことにするか
 */
function hb_login_url_flag(?bool $serve = null, ?bool $hide = null): array
{
    static $state = ['serve' => false, 'hide' => false];

    if ($serve !== null) {
        $state['serve'] = $serve;
    }

    if ($hide !== null) {
        $state['hide'] = $hide;
    }

    return $state;
}

/** 初期化が終わってから、ログイン画面を出すか404にするかを実行する。 */
function hb_login_url_serve(): void
{
    $state = hb_login_url_flag();

    if ($state['hide']) {
        hb_login_url_not_found();
    }

    if (!$state['serve']) {
        return;
    }

    // 「すでにログイン済みなら管理画面へ」などの判断は wp-login.php 自身が行う。
    global $error, $interim_login, $action, $user_login, $user, $redirect_to;

    require_once ABSPATH . 'wp-login.php';

    exit;
}

/** 存在しないものとして返す。リダイレクトにすると新しいURLを教えてしまう。 */
function hb_login_url_not_found(): void
{
    global $wp_query;

    status_header(404);
    nocache_headers();

    if ($wp_query instanceof WP_Query) {
        $wp_query->set_404();
    }

    // テーマの404テンプレートがあればそれを出す。無ければ素のまま終わる。
    $template = get_404_template();

    if ($template) {
        include $template;
        exit;
    }

    wp_die('', '', ['response' => 404]);
}

/* ---------------------------------------------------------------------------
 * URLの書き換え
 * -------------------------------------------------------------------------*/

/**
 * site_url() が返す wp-login.php を新しいURLに置き換える。
 *
 * @param string $url
 * @param string $path
 */
function hb_login_url_replace($url, $path = ''): string
{
    $url = (string) $url;

    if (strpos($url, 'wp-login.php') === false) {
        return $url;
    }

    // クエリ（action=lostpassword など）は保ったまま、入口だけ差し替える。
    $query = (string) parse_url($url, PHP_URL_QUERY);
    $new   = home_url('/' . hb_login_slug() . '/');

    return $query !== '' ? $new . '?' . $query : $new;
}

/** リダイレクト先に wp-login.php が混じっていたら同じく差し替える。 */
function hb_login_url_replace_redirect($location): string
{
    return hb_login_url_replace($location);
}

/* ---------------------------------------------------------------------------
 * 判定の小物
 * -------------------------------------------------------------------------*/

/** sub-directory 設置も考えて、WordPressから見た相対パスにそろえる。 */
function hb_login_url_request_path(): string
{
    $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $base = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');

    if ($base !== '') {
        $path = (string) preg_replace('#^/' . preg_quote($base, '#') . '#', '', $path);
    }

    return $path === '' ? '/' : $path;
}

function hb_login_url_is_admin_path(string $path): bool
{
    return strpos($path, '/wp-admin/') !== false || substr($path, -9) === '/wp-admin';
}

/**
 * 塞いではいけない入口。
 * admin-ajax.php と admin-post.php は公開側からも叩かれる。
 */
function hb_login_url_is_exempt(string $file): bool
{
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return true;
    }

    if (defined('DOING_CRON') && DOING_CRON) {
        return true;
    }

    return in_array($file, ['admin-ajax.php', 'admin-post.php', 'load-scripts.php', 'load-styles.php'], true);
}

/**
 * wp-login.php のままでも通す操作。
 *
 * postpass は公開ページの「パスワード保護記事」のフォーム送信先。
 * 塞ぐと閲覧者が記事を開けなくなる。
 */
function hb_login_url_is_allowed_action(): bool
{
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

    return in_array($action, ['postpass', 'logout'], true);
}
