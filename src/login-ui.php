<?php
/**
 * ログイン画面の整形
 *
 * WordPress 標準のログイン画面は、パスキーやTurnstileを足すと
 * ボタンが横に並んで散らかる。クライアントが毎日触る画面なので、
 * 並びと幅だけは整えておく。
 *
 * ■ 方針: 色と角丸はWordPressの既定のまま
 *   触るのは並び・幅・余白だけ。見た目の味付けはしない。
 *   コアのログイン画面はWordPressの更新で少しずつ変わるので、
 *   色を上書きすると追従できずに浮く。既定に乗っているほうが長持ちする。
 *
 *   案件でブランドに寄せたい場合だけ、定数を書けば色が付く。
 *     define('HB_LOGIN_ACCENT', '#1a4f9c');  // ボタンとフォーカス
 *     define('HB_LOGIN_BG',     '#ffffff');  // 背景
 *     define('HB_LOGIN_RADIUS', 8);          // 角丸(px)
 *     define('HB_LOGIN_LOGO',   'https://example.com/logo.svg'); // URL か添付ID
 *
 * 止めたいときは define('HB_LOGIN_STYLE', false);
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_login_ui_init(): void
{
    if (!hb_base_const('HB_LOGIN_STYLE', true)) {
        return;
    }

    add_action('login_enqueue_scripts', 'hb_login_ui_styles', 20);
    add_filter('login_headerurl', 'hb_login_ui_header_url');
    add_filter('login_headertext', 'hb_login_ui_header_text');
}

/** ロゴのリンク先。既定のWordPress.orgではなくサイトへ返す。 */
function hb_login_ui_header_url(): string
{
    return home_url('/');
}

function hb_login_ui_header_text(): string
{
    return (string) get_bloginfo('name');
}

/** ロゴ画像のURL。定数 → サイトアイコン の順に探し、無ければ差し替えない。 */
function hb_login_ui_logo_url(): string
{
    $logo = hb_base_const('HB_LOGIN_LOGO', '');

    if (is_numeric($logo)) {
        $src = wp_get_attachment_image_url((int) $logo, 'full');

        return $src ? $src : '';
    }

    if (is_string($logo) && $logo !== '') {
        return $logo;
    }

    return (string) get_site_icon_url(160);
}

function hb_login_ui_styles(): void
{
    $css = hb_login_ui_layout_css();

    // 以下は定数を書いた案件でだけ足される。既定では一切出さない。
    $css .= hb_login_ui_brand_css();

    $logo = hb_login_ui_logo_url();

    if ($logo !== '') {
        $css .= hb_login_ui_logo_css($logo);
    }

    wp_add_inline_style('login', apply_filters('hb_login_styles', $css));
}

/**
 * 並び・幅・余白だけ。色も角丸も指定しない。
 *
 * 狙うのはWordPressが昔から使っているIDとクラスだけにする。
 * コア側のマークアップが変わっても、効かなくなるだけで壊れないようにするため。
 */
function hb_login_ui_layout_css(): string
{
    $width = max(300, (int) hb_base_const('HB_LOGIN_WIDTH', 380));

    return "
/* ---- 幅 ---- */
#login {
    width: {$width}px;
    max-width: calc(100vw - 32px);
    padding: 6vh 0 24px;
}

/* ---- ラベルと入力欄 ---- */
.login label {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
}
.login form .input,
.login input[type=text],
.login input[type=password] {
    width: 100%;
    box-sizing: border-box;
    height: 44px;
    margin: 0 0 20px;
    padding: 0 12px;
    font-size: 16px;      /* iOSで勝手に拡大されない下限 */
    line-height: 42px;
}

/* パスワード欄の目のボタンを入力欄の高さに合わせる。 */
.login .wp-pwd .button.wp-hide-pw {
    top: 0;
    height: 44px;
    width: 44px;
}
.login .user-pass-wrap .wp-pwd .input {
    padding-right: 46px;
}

/* ---- 人間確認（Turnstile） ---- */
.login .cf-turnstile {
    margin: 0 0 20px;
    min-height: 65px;     /* 読み込み前に下の要素が飛び跳ねないよう場所を取っておく */
}
.login .cf-turnstile iframe {
    max-width: 100%;
}

/* ---- ログイン状態を保存する ---- */
.login .forgetmenot {
    float: none;
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 20px;
}
.login .forgetmenot label {
    display: inline;
    margin: 0;
    font-weight: 400;
}

/* ---- 送信ボタン ---- */
.login .submit {
    margin: 0;
}
.login #wp-submit {
    float: none;
    width: 100%;
    height: 44px;
    font-size: 15px;
}

/* ---- パスキー ----
   プラグインのJSが、このまとまりを送信ボタンの直後へ移動させる。
   区切り線を足して「別のログイン方法」であることを示す。 */
#secure-passkey-login-wrapper {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #dcdcde;
}
#login-via-passkey {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 44px;
    font-size: 15px;
}

/* ---- カード下のリンク ---- */
#nav,
#backtoblog {
    margin: 16px 0 0;
    padding: 0;
    text-align: center;
}

/* ---- 言語切替は社内用の画面なので隠す ---- */
.login .language-switcher {
    display: none;
}

@media (max-width: 420px) {
    #login {
        padding-top: 3vh;
    }
}
";
}

/**
 * 案件でブランドに寄せたいときだけ出す色と角丸。
 * 定数が無ければ空文字を返し、WordPressの既定のままにする。
 */
function hb_login_ui_brand_css(): string
{
    $css = '';

    $bg = sanitize_hex_color((string) hb_base_const('HB_LOGIN_BG', ''));

    if ($bg) {
        $css .= "\nbody.login { background: {$bg}; }\n";
    }

    $accent = sanitize_hex_color((string) hb_base_const('HB_LOGIN_ACCENT', ''));

    if ($accent) {
        $css .= "
.login #wp-submit {
    background: {$accent};
    border-color: {$accent};
}
.login #wp-submit:hover,
.login #wp-submit:focus {
    background: {$accent};
    border-color: {$accent};
    filter: brightness(.92);
}
.login form .input:focus,
.login input[type=text]:focus,
.login input[type=password]:focus {
    border-color: {$accent};
    box-shadow: 0 0 0 1px {$accent};
}
#login-via-passkey {
    color: {$accent};
    border-color: {$accent};
}
#nav a:hover,
#backtoblog a:hover {
    color: {$accent};
}
";
    }

    $radius = (int) hb_base_const('HB_LOGIN_RADIUS', 0);

    if ($radius > 0) {
        $css .= "
.login form,
.login form .input,
.login input[type=text],
.login input[type=password],
.login #wp-submit,
#login-via-passkey {
    border-radius: {$radius}px;
}
";
    }

    return $css;
}

/** ロゴ差し替え。高さだけ決めて、幅は画像の比率に任せる。 */
function hb_login_ui_logo_css(string $url): string
{
    return "
#login h1 a,
.login .wp-login-logo a {
    background-image: url('" . esc_url($url) . "');
    background-size: contain;
    background-position: center;
    width: 100%;
    height: 56px;
    margin-bottom: 18px;
}
";
}
