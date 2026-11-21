<?php
/**
 * ログイン画面以外の入口を塞ぐ
 *
 * ログイン画面をいくら固めても、同じ資格情報を試せる入口が他にあれば意味がない。
 * WordPress には既定で開いている横道が2つある。
 *
 * ■ XML-RPC（/xmlrpc.php）
 *   パスワードで認証できる。しかも system.multicall を使うと
 *   1リクエストに何百回分もの試行を詰め込めるので、
 *   「1回のアクセスを1回と数える」種類の回数制限をすり抜ける。
 *   Jetpack と WordPress公式アプリが使うが、企業サイトでは普通どちらも使わない。
 *
 * ■ REST API のユーザー一覧（/wp-json/wp/v2/users）
 *   未ログインでも、投稿のあるユーザーの slug（＝ログイン名）を返す。
 *   著者ページを404にしても、こちらが開いていれば同じことが調べられる。
 *
 * ■ アプリケーションパスワード
 *   REST や XML-RPC 用の別パスワード。これで認証されると、
 *   パスキーも人間確認も試行回数制限も**全部迂回される**。
 *   外部連携を実際に使っている案件以外では持っていても損しかない。
 *
 * 必要な案件だけ元に戻す:
 *   define('HB_XMLRPC', true);          // XML-RPCを使う
 *   define('HB_REST_USERS', true);      // ユーザー一覧を未ログインにも見せる
 *   define('HB_APP_PASSWORDS', true);   // アプリケーションパスワードを使う
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_hardening_init(): void
{
    if (!hb_base_const('HB_XMLRPC', false)) {
        hb_hardening_disable_xmlrpc();
    }

    if (!hb_base_const('HB_REST_USERS', false)) {
        add_filter('rest_endpoints', 'hb_hardening_hide_rest_users');
    }

    if (!hb_base_const('HB_APP_PASSWORDS', false)) {
        // 発行済みのものも含めて認証に使えなくなり、プロフィール画面からも消える。
        add_filter('wp_is_application_passwords_available', '__return_false');
    }
}

/* ---------------------------------------------------------------------------
 * XML-RPC
 * -------------------------------------------------------------------------*/

function hb_hardening_disable_xmlrpc(): void
{
    // 認証を伴うメソッドを止める。これで総当たりの入口が閉じる。
    add_filter('xmlrpc_enabled', '__return_false');

    // 「ここにXML-RPCがあります」という案内も消す。
    remove_action('wp_head', 'rsd_link');
    add_filter('wp_headers', 'hb_hardening_remove_pingback_header');

    // ピンバックの送受信も止める。これも xmlrpc.php 経由。
    add_filter('xmlrpc_methods', 'hb_hardening_remove_pingback_methods');
    add_filter('pings_open', '__return_false', 20);
}

/** @param array $headers */
function hb_hardening_remove_pingback_header($headers): array
{
    $headers = is_array($headers) ? $headers : [];

    unset($headers['X-Pingback']);

    return $headers;
}

/** @param array $methods */
function hb_hardening_remove_pingback_methods($methods): array
{
    $methods = is_array($methods) ? $methods : [];

    unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);

    return $methods;
}

/* ---------------------------------------------------------------------------
 * REST API のユーザー一覧
 * -------------------------------------------------------------------------*/

/**
 * 未ログインからはユーザーの経路そのものを消す。
 *
 * 権限で弾くのではなく経路を外すので、/wp-json/ でも ?rest_route= でも同じく届かない。
 * ログイン中（ブロックエディタなど）はこれまでどおり使える。
 *
 * @param array $endpoints
 */
function hb_hardening_hide_rest_users($endpoints): array
{
    $endpoints = is_array($endpoints) ? $endpoints : [];

    if (is_user_logged_in()) {
        return $endpoints;
    }

    unset(
        $endpoints['/wp/v2/users'],
        $endpoints['/wp/v2/users/(?P<id>[\d]+)'],
        $endpoints['/wp/v2/users/me']
    );

    return $endpoints;
}

/** 管理画面に出す状態。 */
function hb_hardening_summary(): string
{
    $items = [];

    $items[] = hb_base_const('HB_XMLRPC', false)
        ? 'XML-RPC: <strong>開いている</strong>'
        : 'XML-RPC: 停止';

    $items[] = hb_base_const('HB_REST_USERS', false)
        ? 'RESTのユーザー一覧: <strong>公開</strong>'
        : 'RESTのユーザー一覧: 未ログインには出さない';

    $items[] = hb_base_const('HB_APP_PASSWORDS', false)
        ? 'アプリケーションパスワード: <strong>有効</strong>'
        : 'アプリケーションパスワード: 停止';

    $items[] = hb_base_const('HB_AUTHOR_ARCHIVES', false)
        ? '著者ページ: <strong>公開</strong>'
        : '著者ページ: 出さない';

    return implode(' ・ ', $items);
}
