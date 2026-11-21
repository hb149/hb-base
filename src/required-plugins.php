<?php
/**
 * 標準プラグインのまとめ導入
 *
 * 新しい案件を立てるたびに同じプラグインを手で入れて回るのをやめる。
 * 「HBベース」の画面に一覧と導入ボタンを出す。
 *
 * ■ なぜ「必須依存」にしないか
 *   WordPress 6.5 以降はプラグインヘッダに `Requires Plugins:` と書くと
 *   依存関係を宣言できるが、**依存が揃っていないと hb-base 自体が有効化できない**。
 *   hb-base は管理画面ガードやログインURLを担っている土台なので、
 *   「別のプラグインが入っていないから土台ごと止まる」のは本末転倒になる。
 *
 *   そのため依存にはせず、入っていなければ各モジュールが黙って何もしない作りにしてある
 *   （パスキー日本語化もログイン制限の初期設定も、相手が居なければ素通りする）。
 *   導入はこちらの画面から任意で行う。
 *
 * ■ 設定
 *   define('HB_REQUIRED_PLUGINS', 'limit-login-attempts-reloaded, secure-passkeys, safe-svg');
 *   WordPress.org のスラッグを並べる。有料プラグインは扱えない（下記）。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 標準で入れたいプラグイン（WordPress.org のスラッグ）。
 *
 * 既定は hb-base が実際に連携している2本だけにしてある。
 * 案件の好みで足すものは wp-config.php かフィルタで。
 *
 * ACF PRO のような有料プラグインはここでは扱えない（.org に無いため）。
 */
function hb_required_plugins(): array
{
    $slugs = hb_base_const('HB_REQUIRED_PLUGINS');

    if ($slugs === null) {
        $slugs = [
            'limit-login-attempts-reloaded', // hb-base が初期設定を投入する
            'secure-passkeys',               // hb-base が日本語化と見出し補修を行う
        ];
    }

    return apply_filters('hb_required_plugins', hb_base_to_list($slugs));
}

/**
 * スラッグごとの状態を調べる。
 *
 * @return array<string, array{slug:string,file:string,name:string,state:string}>
 */
function hb_required_plugins_status(): array
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $installed = get_plugins();
    $out       = [];

    foreach (hb_required_plugins() as $slug) {
        $file = '';
        $name = $slug;

        foreach ($installed as $path => $data) {
            // 「スラッグ/なにか.php」の形。1ファイル構成のプラグインも拾う。
            if (dirname($path) === $slug || $path === $slug . '.php') {
                $file = $path;
                $name = (string) $data['Name'];
                break;
            }
        }

        if ($file === '') {
            $state = 'missing';
        } elseif (is_plugin_active($file)) {
            $state = 'active';
        } else {
            $state = 'inactive';
        }

        $out[$slug] = ['slug' => $slug, 'file' => $file, 'name' => $name, 'state' => $state];
    }

    return $out;
}

/**
 * 1本を導入する。入っていれば有効化するだけ。
 *
 * @return true|WP_Error
 */
function hb_required_plugins_install(string $slug)
{
    if (!current_user_can('install_plugins')) {
        return new WP_Error('hb_forbidden', 'プラグインを導入する権限がありません。');
    }

    $status = hb_required_plugins_status();

    if (!isset($status[$slug])) {
        return new WP_Error('hb_unknown', '対象のプラグインが一覧にありません。');
    }

    $info = $status[$slug];

    if ($info['state'] === 'active') {
        return true;
    }

    // すでにファイルがあるなら有効化だけ。
    if ($info['state'] === 'inactive') {
        $result = activate_plugin($info['file']);

        return is_wp_error($result) ? $result : true;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    // サーバーによってはWordPressが直接書き込めず、FTPの資格情報を求める。
    // その場合は黙って失敗するより、何が足りないかを言う。
    if (get_filesystem_method() !== 'direct') {
        return new WP_Error(
            'hb_no_direct_fs',
            'このサーバーでは WordPress が直接ファイルを書き込めません。'
            . '管理画面からの導入はできないので、プラグインは手動で追加してください。'
        );
    }

    $api = plugins_api('plugin_information', [
        'slug'   => $slug,
        'fields' => ['sections' => false],
    ]);

    if (is_wp_error($api)) {
        return $api;
    }

    // 画面に進捗を垂れ流さない skin を使う。失敗はエラーとして受け取る。
    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);

    $installed = $upgrader->install($api->download_link);

    if (is_wp_error($installed)) {
        return $installed;
    }

    if ($skin->get_errors()->has_errors()) {
        return $skin->get_errors();
    }

    if ($installed !== true) {
        return new WP_Error('hb_install_failed', 'インストールに失敗しました。ファイルの書き込み権限を確認してください。');
    }

    $file = $upgrader->plugin_info();

    if (!$file) {
        return new WP_Error('hb_install_failed', 'インストール後にプラグイン本体を特定できませんでした。');
    }

    $result = activate_plugin($file);

    return is_wp_error($result) ? $result : true;
}

/**
 * 足りないものをまとめて導入する。
 *
 * @return array{ok:string[], failed:array<string,string>}
 */
function hb_required_plugins_install_all(): array
{
    $ok     = [];
    $failed = [];

    foreach (hb_required_plugins_status() as $slug => $info) {
        if ($info['state'] === 'active') {
            continue;
        }

        $result = hb_required_plugins_install($slug);

        if (is_wp_error($result)) {
            $failed[$slug] = $result->get_error_message();
        } else {
            $ok[] = $slug;
        }
    }

    return ['ok' => $ok, 'failed' => $failed];
}
