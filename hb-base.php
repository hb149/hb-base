<?php
/**
 * Plugin Name: HB ベース
 * Description: WordPress案件で共通して使う土台。ログイン画面の整形・パスキーとACF Extendedの日本語化・Cloudflare Turnstile・管理画面のアクセス制限（固定IP＋端末許可）。設定は wp-config.php の定数で行う。
 * Version:     1.0.0
 * Update URI:  https://github.com/hb149/hb-base
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: hb-base
 *
 * 配布のしかた:
 *   本体のソースは ~/作業場/Web/WP共通/hb-base/（gitリポジトリ）。
 *   タグを打って GitHub に Release を出すと、各サイトのプラグイン一覧に
 *   「更新があります」が出る。各サイトで一度「自動更新を有効化」しておけば、
 *   以後はサイト側のcronが勝手に上げるので、案件ごとの手作業は要らない。
 *   開発中のローカルは、このディレクトリを wp-content/plugins/hb-base に
 *   マウントして直接編集する。
 *
 * 通常プラグインである理由:
 *   mu-plugins は WordPress の更新機構に乗らない（管理画面から更新できない）。
 *   また、管理画面ガードで締め出された時に `wp plugin deactivate hb-base` で
 *   復帰できるのは通常プラグインだけ。
 *   「テーマを替えても消えない」ことが目的なら、無効化しにくさよりも
 *   更新できることを優先した。
 *
 * 案件固有の事情はここに書かないこと。案件側の mu-plugins に置く。
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HB_BASE_VERSION', '1.0.0');
define('HB_BASE_DIR', __DIR__);
define('HB_BASE_FILE', __FILE__);

require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/ip.php';
require_once __DIR__ . '/src/device.php';
require_once __DIR__ . '/src/admin-guard.php';
require_once __DIR__ . '/src/turnstile.php';
require_once __DIR__ . '/src/login-ui.php';
require_once __DIR__ . '/src/limit-login.php';
require_once __DIR__ . '/src/passkey-ja.php';
require_once __DIR__ . '/src/acfe-ja.php';
require_once __DIR__ . '/src/passkey-admin.php';
require_once __DIR__ . '/src/updater.php';
require_once __DIR__ . '/src/admin-screen.php';

hb_github_updater_register([
    'file'         => __FILE__,
    'slug'         => 'hb-base',
    'name'         => 'HB ベース',
    'repo'         => (string) hb_base_const('HB_BASE_UPDATE_REPO', 'hb149/hb-base'),
    'version'      => HB_BASE_VERSION,
    'tested'       => '7.1',
    'requires_php' => '7.4',
]);

/**
 * モジュールは定数で個別に止められる。
 * 何も設定していないサイトに入れただけでは、締め出しも認証追加も起きない
 * （ガードの既定は off、Turnstile はキー未設定なら何もしない）。
 */
add_action('plugins_loaded', 'hb_base_boot', 1);

function hb_base_boot(): void
{
    if (hb_base_module_enabled('passkey_ja')) {
        hb_passkey_ja_init();
        if (is_admin()) {
            hb_passkey_admin_init();
        }
    }
    if (hb_base_module_enabled('acfe_ja')) {
        hb_acfe_ja_init();
    }
    if (hb_base_module_enabled('admin_guard')) {
        hb_admin_guard_init();
    }
    if (hb_base_module_enabled('turnstile')) {
        hb_turnstile_init();
    }
    if (hb_base_module_enabled('login_ui')) {
        hb_login_ui_init();
    }
    if (hb_base_module_enabled('limit_login')) {
        hb_limit_login_init();
    }
    if (is_admin()) {
        hb_admin_screen_init();
    }
}
