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

    // プロフィール画面の節見出し。プラグイン本体は優先度1で出力するので、その手前に差す。
    add_action('show_user_profile', 'hb_passkey_profile_heading', 0);
    add_action('edit_user_profile', 'hb_passkey_profile_heading', 0);

    add_action('admin_enqueue_scripts', 'hb_passkey_profile_style');
}

/**
 * 見出しと表のあいだに空く隙間を詰める。
 *
 * プロフィール画面の他の節は「見出しの直後が表」なので、
 * 管理画面のCSSが表の上余白を0にしている。
 * ところがこのプラグインは表を <div> 2枚で包んでいるため隣接と見なされず、
 * 表の既定の margin-top: 7px がそのまま残って下にずれる。
 *
 * ACFの設定では直せない種類のずれなので、ここだけCSSで詰める。宣言は1つに留める。
 */
function hb_passkey_profile_style(string $hook): void
{
    if (!in_array($hook, ['profile.php', 'user-edit.php'], true)) {
        return;
    }

    wp_add_inline_style('wp-admin', 'table.form-table.secure-passkeys{margin-top:0}');
}

/**
 * プロフィール画面のパスキー欄に節見出しを付ける。
 *
 * プラグインは <table class="form-table secure-passkeys"> をいきなり出力し、
 * 他の節（「名前」「連絡先情報」「アプリケーションパスワード」など）にある
 * <h2> を持たない。そのため ACF Extended が節をカードに包む処理から漏れ、
 * この欄だけ見出しの無い裸の表になる。
 *
 * 見出しを1つ足すだけで、他の節と同じ見え方に揃う。
 *
 * @param WP_User $user
 */
function hb_passkey_profile_heading($user): void
{
    if (!hb_passkey_profile_section_shown($user)) {
        return;
    }

    echo '<h2>パスキー</h2>';
}

/**
 * プラグイン側がこの欄を実際に出すかどうか。
 * 出ないのに見出しだけ残ると、空の節ができて不自然になる。
 *
 * @param WP_User $user
 */
function hb_passkey_profile_section_shown($user): bool
{
    $helper = '\\Secure_Passkeys\\Utils\\Secure_Passkeys_Helper';

    // プラグインが無い・作りが変わったときは、余計なことをせず黙る。
    if (!class_exists($helper)
        || !method_exists($helper, 'get_option')
        || !method_exists($helper, 'is_user_in_excluded_roles')) {
        return false;
    }

    if (!$helper::get_option('display_passkey_edit_user_enabled', 0)) {
        return false;
    }

    return !$helper::is_user_in_excluded_roles((int) $user->ID);
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

    // .wrap で包む。管理画面の見出しの余白は .wrap のCSSが持っているので、
    // 裸の <h1> を置くと管理バーの下に潜り込んで文字が切れる。
    printf(
        '<div class="wrap"><h1 class="wp-heading-inline">%s</h1></div>',
        esc_html($title)
    );
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
