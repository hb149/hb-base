<?php
/**
 * Secure Passkeys の管理画面に見出しを足す
 *
 * このプラグインの管理ページは Vue で描かれていて、<h1> は
 * 読み込みスピナーを入れるためだけの空要素になっている
 * （components/settings/general-settings.vue.php など）。
 * 結果、設定ページはタブがいきなり始まり、いま何の画面なのか分からない。
 *
 * WordPress の他の画面と同じ見え方にするため、こちらでページ名を出す。
 * プラグイン本体は触らないので更新で消えない。
 * 本家が見出しを付けたら、このファイルは不要になる（重複したら消すこと）。
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_passkey_admin_init(): void
{
    // 見出しは通知より先に出す。WordPressの他の画面と同じ並びにするため。
    add_action('all_admin_notices', 'hb_passkey_admin_heading', 1);
}

function hb_passkey_admin_heading(): void
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    if (strpos($page, 'secure-passkeys') !== 0) {
        return;
    }

    $title = hb_passkey_admin_title($page);

    if ($title === '') {
        return;
    }

    printf('<h1 class="wp-heading-inline" style="margin:0 0 .5em">%s</h1>', esc_html($title));
}

/**
 * ページ名。
 *
 * 「設定」だけでは何の設定か分からないので、どの画面にもパスキーだと分かる名前を付ける。
 */
function hb_passkey_admin_title(string $page): string
{
    $titles = [
        'secure-passkeys-overview' => 'パスキー',
        'secure-passkeys-list'     => '登録済みのパスキー',
        'secure-passkeys-activity' => 'パスキーの操作ログ',
        'secure-passkeys-settings' => 'パスキーの設定',
    ];

    if (isset($titles[$page])) {
        return $titles[$page];
    }

    // 知らないページが増えても、WordPressが持っている名前で出しておく。
    $fallback = function_exists('get_admin_page_title') ? (string) get_admin_page_title() : '';

    return $fallback !== '' ? 'パスキー｜' . $fallback : '';
}
