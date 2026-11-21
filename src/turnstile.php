<?php
/**
 * Cloudflare Turnstile
 *
 * ログイン・パスワード再発行・ユーザー登録のフォームに人間確認を足す。
 * reCAPTCHA と違い利用者の操作が要らず、Cookieも置かないので同意取得の話にならない。
 *
 * キーが未設定なら何もしない。案件ごとに wp-config.php で:
 *   define('HB_TURNSTILE_SITE_KEY',   '0x...');
 *   define('HB_TURNSTILE_SECRET_KEY', '0x...');
 *
 * ローカル検証用に Cloudflare が常に成功するテストキーを公開している:
 *   site   1x00000000000000000000AA
 *   secret 1x0000000000000000000000000000000AA
 *
 * パスキーでのログインはこの検証を通らない。別経路（admin-ajax）で、
 * 総当たりの的にならず（秘密を送らない）、プラグイン側に回数制限もあるため、
 * わざと対象外にしている。守りたいのはパスワードの総当たり。
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const HB_TURNSTILE_FIELD      = 'cf-turnstile-response';

function hb_turnstile_init(): void
{
    if (!hb_turnstile_configured()) {
        return;
    }

    $forms = hb_turnstile_forms();

    add_action('login_enqueue_scripts', 'hb_turnstile_enqueue');

    if (in_array('login', $forms, true)) {
        add_action('login_form', 'hb_turnstile_widget');
        add_filter('authenticate', 'hb_turnstile_check_login', 25, 3);
    }

    if (in_array('lostpassword', $forms, true)) {
        add_action('lostpassword_form', 'hb_turnstile_widget');
        add_action('lostpassword_post', 'hb_turnstile_check_lostpassword');
    }

    if (in_array('register', $forms, true)) {
        add_action('register_form', 'hb_turnstile_widget');
        add_filter('registration_errors', 'hb_turnstile_check_register');
    }
}

function hb_turnstile_configured(): bool
{
    return hb_turnstile_site_key() !== '' && hb_turnstile_secret_key() !== '';
}

/* ---------------------------------------------------------------------------
 * 表示
 * -------------------------------------------------------------------------*/

function hb_turnstile_enqueue(): void
{
    wp_enqueue_script(
        'cf-turnstile',
        'https://challenges.cloudflare.com/turnstile/v0/api.js',
        [],
        null,
        false
    );

    // ログインボックスの幅に収める。これ以上の装飾はしない。
    wp_add_inline_style('login', '.cf-turnstile{margin:0 0 16px}.cf-turnstile iframe{max-width:100%}');
}

/** Cloudflare のスクリプトは async/defer 必須。 */
add_filter('script_loader_tag', 'hb_turnstile_script_tag', 10, 2);

function hb_turnstile_script_tag(string $tag, string $handle): string
{
    if ($handle !== 'cf-turnstile') {
        return $tag;
    }

    return str_replace('<script ', '<script async defer ', $tag);
}

function hb_turnstile_widget(): void
{
    printf(
        '<div class="cf-turnstile" data-sitekey="%s" data-language="ja" data-theme="auto" data-retry="auto"></div>',
        esc_attr(hb_turnstile_site_key())
    );
}

/* ---------------------------------------------------------------------------
 * 検証
 * -------------------------------------------------------------------------*/

/**
 * ログインフォームからのPOSTだけを対象にする。
 * XML-RPC・アプリケーションパスワード・REST からの認証まで巻き込むと、
 * 画面の無い経路が全部死ぬ。
 */
function hb_turnstile_is_form_post(): bool
{
    if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
        return false;
    }

    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        return false;
    }

    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    return basename($path) === 'wp-login.php';
}

/**
 * @param WP_User|WP_Error|null $user
 * @return WP_User|WP_Error|null
 */
function hb_turnstile_check_login($user, $username, $password)
{
    // 入力前の素の表示や、別経路からの認証には手を出さない。
    if ($username === '' || !hb_turnstile_is_form_post()) {
        return $user;
    }

    $result = hb_turnstile_verify();

    if ($result === true) {
        return $user;
    }

    return new WP_Error('hb_turnstile', $result);
}

function hb_turnstile_check_lostpassword(): void
{
    if (!hb_turnstile_is_form_post()) {
        return;
    }

    $result = hb_turnstile_verify();

    if ($result !== true) {
        wp_die(esc_html($result), 'エラー', ['response' => 403, 'back_link' => true]);
    }
}

/** @param WP_Error $errors */
function hb_turnstile_check_register($errors)
{
    if (!hb_turnstile_is_form_post()) {
        return $errors;
    }

    $result = hb_turnstile_verify();

    if ($result !== true) {
        $errors->add('hb_turnstile', $result);
    }

    return $errors;
}

/**
 * Cloudflare に問い合わせる。
 *
 * @return true|string 成功なら true、失敗なら画面に出す理由
 */
function hb_turnstile_verify()
{
    $token = isset($_POST[HB_TURNSTILE_FIELD]) ? (string) wp_unslash($_POST[HB_TURNSTILE_FIELD]) : '';

    if ($token === '') {
        return '<strong>エラー:</strong> 人間確認が完了していません。ページを再読み込みして、もう一度お試しください。';
    }

    $response = wp_remote_post(HB_TURNSTILE_VERIFY_URL, [
        'timeout' => 7,
        'body'    => [
            'secret'   => hb_turnstile_secret_key(),
            'response' => $token,
            'remoteip' => hb_client_ip(),
        ],
    ]);

    if (is_wp_error($response)) {
        // Cloudflare に届かなかった。本番は止め、ローカルは通す（既定）。
        if (hb_turnstile_fail_open()) {
            return true;
        }

        return '<strong>エラー:</strong> 人間確認のサーバーに接続できませんでした。時間をおいてお試しください。';
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);

    if (!empty($body['success'])) {
        return true;
    }

    $codes = isset($body['error-codes']) && is_array($body['error-codes']) ? $body['error-codes'] : [];

    // 使い捨てトークンの二度押しだけは、利用者に非がないので文言を分ける。
    if (in_array('timeout-or-duplicate', $codes, true)) {
        return '<strong>エラー:</strong> 人間確認の有効期限が切れました。ページを再読み込みしてお試しください。';
    }

    return '<strong>エラー:</strong> 人間確認に失敗しました。もう一度お試しください。';
}
