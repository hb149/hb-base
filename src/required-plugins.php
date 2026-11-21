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
            'limit-login-attempts-reloaded', // ログイン試行の制限。hb-base が初期設定を投入する
            'secure-passkeys',               // パスキー。hb-base が日本語化と見出し補修を行う
            'public-post-preview',           // 未公開記事をクライアントに見せる
            'safe-svg',                      // SVGアップロードの無害化
            'seo-simple-pack',               // タイトル・説明・OGP
            'snow-monkey-forms',             // 問い合わせフォーム
            'duplicate-post',                // 記事の複製
            'crop-thumbnails',               // サムネイルの切り抜き位置を手で決める
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

    return hb_required_plugins_install_now($slug);
}

/**
 * 権限を見ずに導入する。裏で動かすとき（cron）はログイン中の人が居ないため。
 * 画面から呼ぶときは必ず hb_required_plugins_install() を通すこと。
 *
 * @return true|WP_Error
 */
function hb_required_plugins_install_now(string $slug)
{
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

/* ---------------------------------------------------------------------------
 * 自動有効化と、停止させない仕掛け
 * -------------------------------------------------------------------------*/

const HB_PLUGIN_OPTOUT = 'hb_plugin_optout';

function hb_required_plugins_init(): void
{
    // 停止の記録は管理画面に限らない。WP-CLI から止めることもある。
    add_action('deactivated_plugin', 'hb_required_plugins_remember_optout', 10, 1);

    // 自動ダウンロード。cron から動くので表示側でも登録する。
    hb_required_plugins_auto_install_init();

    if (!is_admin()) {
        return;
    }

    if (hb_base_const('HB_FORCE_ACTIVATE', true)) {
        add_action('admin_init', 'hb_required_plugins_autoactivate', 5);
    }

    // クライアントが誤って止めないよう「停止」リンクを隠す。保守担当には見せる。
    add_filter('plugin_action_links', 'hb_required_plugins_lock_links', 10, 2);
}

/**
 * 入っているのに停止しているものを有効化する。
 *
 * ダウンロードはしない。ここで通信すると管理画面が重くなるし、
 * 失敗したときに原因が分かりにくい。未導入のものは画面のボタンから入れる。
 */
function hb_required_plugins_autoactivate(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $optout = hb_required_plugins_optout();

    foreach (hb_required_plugins_status() as $slug => $info) {
        if ($info['state'] !== 'inactive' || in_array($slug, $optout, true)) {
            continue;
        }

        activate_plugin($info['file']);
    }
}

/** 自動有効化の対象から外したものの一覧。 */
function hb_required_plugins_optout(): array
{
    $list = get_option(HB_PLUGIN_OPTOUT, []);

    return is_array($list) ? $list : [];
}

/**
 * 保守担当が止めたときだけ、そのプラグインを以後ほうっておく。
 * そうしないと、止めた直後に自動で戻って止められなくなる。
 *
 * 逆に、保守担当以外が止めた場合は覚えない。
 * 一覧の「停止」リンクは隠してあるが、一括操作からは止められてしまうので、
 * そちらは次に管理画面を開いた時点で有効に戻る。
 *
 * WP-CLI はサーバーを触れる立場なので保守担当として扱う。
 */
function hb_required_plugins_remember_optout(string $plugin): void
{
    $by_maintainer = (defined('WP_CLI') && WP_CLI) || hb_is_maintainer();

    if (!$by_maintainer) {
        return;
    }

    $slug = dirname($plugin);

    if ($slug === '.' ) {
        $slug = basename($plugin, '.php');
    }

    if (!in_array($slug, hb_required_plugins(), true)) {
        return;
    }

    $list = hb_required_plugins_optout();

    if (!in_array($slug, $list, true)) {
        $list[] = $slug;
        update_option(HB_PLUGIN_OPTOUT, $list, false);
    }
}

/** 自動有効化の対象に戻す。 */
function hb_required_plugins_clear_optout(string $slug): void
{
    update_option(
        HB_PLUGIN_OPTOUT,
        array_values(array_diff(hb_required_plugins_optout(), [$slug])),
        false
    );
}

/**
 * 標準プラグインの「停止」リンクを隠す。
 *
 * 権限は変えない。リンクを出さないだけなので、保守担当やWP-CLIからは普通に止められる。
 */
function hb_required_plugins_lock_links(array $links, string $plugin): array
{
    if (!hb_base_const('HB_LOCK_REQUIRED_PLUGINS', true)) {
        return $links;
    }

    if (hb_is_maintainer()) {
        return $links;
    }

    $slug = dirname($plugin);

    if (!in_array($slug, hb_required_plugins(), true)) {
        return $links;
    }

    unset($links['deactivate']);

    $links['hb-locked'] = '<span style="color:#646970">サイトの標準構成</span>';

    return $links;
}

/* ---------------------------------------------------------------------------
 * 自動ダウンロード
 *
 * 8本を1リクエストで落とすと実行時間の上限に当たるので、裏で1本ずつ入れる。
 * 管理画面を開いた時点で予約を入れ、cron が1本ずつ処理して、
 * 残りがあれば自分で次を予約する。
 * -------------------------------------------------------------------------*/

const HB_INSTALL_HOOK  = 'hb_install_required_plugin';
const HB_INSTALL_FAILS = 'hb_plugin_install_fails';
const HB_INSTALL_LIMIT = 3;   // 同じプラグインで続けて失敗したら諦める回数

function hb_required_plugins_auto_install_init(): void
{
    add_action(HB_INSTALL_HOOK, 'hb_required_plugins_install_next');

    if (!is_admin() || !hb_base_const('HB_AUTO_INSTALL', true)) {
        return;
    }

    add_action('admin_init', 'hb_required_plugins_schedule', 6);
    add_action('admin_notices', 'hb_required_plugins_progress_notice');
}

/** 足りないものがあれば、裏での導入を予約する。 */
function hb_required_plugins_schedule(): void
{
    if (wp_next_scheduled(HB_INSTALL_HOOK)) {
        return;
    }

    if (hb_required_plugins_pending() === []) {
        return;
    }

    // すぐ後ろに置く。管理画面の次の読み込みか、サイトへのアクセスで動く。
    wp_schedule_single_event(time() + 10, HB_INSTALL_HOOK);
}

/**
 * まだ入っていなくて、諦めてもいないものの一覧。
 *
 * @return string[]
 */
function hb_required_plugins_pending(): array
{
    $optout = hb_required_plugins_optout();
    $fails  = hb_required_plugins_fails();
    $out    = [];

    foreach (hb_required_plugins_status() as $slug => $info) {
        if ($info['state'] !== 'missing') {
            continue;
        }
        if (in_array($slug, $optout, true)) {
            continue;
        }
        if (($fails[$slug] ?? 0) >= HB_INSTALL_LIMIT) {
            continue;
        }

        $out[] = $slug;
    }

    return $out;
}

function hb_required_plugins_fails(): array
{
    $fails = get_option(HB_INSTALL_FAILS, []);

    return is_array($fails) ? $fails : [];
}

/** cron から呼ばれる。1本だけ入れて、残りがあれば次を予約する。 */
function hb_required_plugins_install_next(): void
{
    $pending = hb_required_plugins_pending();

    if ($pending === []) {
        return;
    }

    $slug   = $pending[0];
    $result = hb_required_plugins_install_now($slug);

    $fails = hb_required_plugins_fails();

    if (is_wp_error($result)) {
        $fails[$slug] = ($fails[$slug] ?? 0) + 1;
        $fails['_last_error'] = $slug . ': ' . $result->get_error_message();
    } else {
        unset($fails[$slug]);
    }

    update_option(HB_INSTALL_FAILS, $fails, false);

    if (hb_required_plugins_pending() !== []) {
        wp_schedule_single_event(time() + 10, HB_INSTALL_HOOK);

        return;
    }

    // 全部そろったら予約を片付ける。空振りの予定を残さない。
    $next = wp_next_scheduled(HB_INSTALL_HOOK);

    if ($next) {
        wp_unschedule_event($next, HB_INSTALL_HOOK);
    }
}

/** 導入中・導入できなかったことを管理画面に出す。黙って進むと不安になるため。 */
function hb_required_plugins_progress_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $pending = hb_required_plugins_pending();

    if (!empty($pending)) {
        printf(
            '<div class="notice notice-info"><p>標準プラグインを裏で導入しています（残り %d 本）。'
            . 'しばらくしてから画面を再読み込みしてください。</p></div>',
            count($pending)
        );

        return;
    }

    $fails = hb_required_plugins_fails();
    $stuck = [];

    foreach ($fails as $slug => $count) {
        if ($slug !== '_last_error' && (int) $count >= HB_INSTALL_LIMIT) {
            $stuck[] = $slug;
        }
    }

    if (empty($stuck)) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>標準プラグインを導入できませんでした: <code>%s</code><br>'
        . '<small>%s</small><br>'
        . '<a href="%s">HBベースの画面</a>から手動で導入するか、サーバーの書き込み権限を確認してください。</p></div>',
        esc_html(implode(', ', $stuck)),
        esc_html((string) ($fails['_last_error'] ?? '')),
        esc_url(admin_url('options-general.php?page=hb-base'))
    );
}
