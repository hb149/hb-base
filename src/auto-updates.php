<?php
/**
 * 自動更新をはじめから有効にする
 *
 * ■ なぜ
 *   更新を人手に頼ると必ず溜まる。溜まったサイトが攻撃の入口になる。
 *   WordPress の自動更新はよくできているので、最初から全部オンにしておく。
 *
 * ■ 入れる値
 *   プラグイン … 全部オン（あとから入れたものも自動でオンにする）
 *   テーマ     … 全部オン
 *   本体       … マイナー更新（7.1.4 → 7.1.5）はオン。これは WordPress の既定でもある
 *                 メジャー更新（7.1 → 7.2）は既定でオフ
 *
 * ■ メジャー更新を既定でオフにしている理由
 *   メジャー更新はテーマやプラグインの互換性が崩れることがある。
 *   クライアントのサイトが朝いきなり壊れているのは避けたいので、
 *   こちらで確認してから上げる前提にしている。
 *
 *   全部任せたいなら define('HB_AUTO_UPDATE_CORE', 'major');
 *   本体の自動更新を止めるなら define('HB_AUTO_UPDATE_CORE', 'off');
 *
 * ■ 固定はしない
 *   プラグイン一覧の「自動更新を有効化／無効化」はそのまま使える。
 *   こちらが毎回上書きすることはない。
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_AUTOUPDATE_MARKER = 'hb_auto_updates_seeded';

function hb_auto_updates_init(): void
{
    if (!hb_base_const('HB_AUTO_UPDATES', true)) {
        return;
    }

    add_action('admin_init', 'hb_auto_updates_seed', 15);

    // あとから入れたものも対象にする。入れっぱなしで古くなるのを防ぐ。
    add_action('upgrader_process_complete', 'hb_auto_updates_catch_new', 10, 2);

    hb_auto_updates_core();
}

/* ---------------------------------------------------------------------------
 * 本体
 * -------------------------------------------------------------------------*/

/** 'minor'（既定）/ 'major' / 'off' */
function hb_auto_updates_core_policy(): string
{
    $policy = strtolower((string) hb_base_const('HB_AUTO_UPDATE_CORE', 'minor'));

    return in_array($policy, ['minor', 'major', 'off'], true) ? $policy : 'minor';
}

function hb_auto_updates_core(): void
{
    $policy = hb_auto_updates_core_policy();

    // wp-config.php に WP_AUTO_UPDATE_CORE があればそちらが優先される。
    // 案件側で明示している意思を上書きしない。
    if (defined('WP_AUTO_UPDATE_CORE')) {
        return;
    }

    add_filter('allow_minor_auto_core_updates', static function () use ($policy) {
        return $policy !== 'off';
    }, 20);

    add_filter('allow_major_auto_core_updates', static function () use ($policy) {
        return $policy === 'major';
    }, 20);
}

/* ---------------------------------------------------------------------------
 * プラグインとテーマ
 * -------------------------------------------------------------------------*/

/**
 * いま入っているものを一度だけ全部オンにする。
 *
 * 一覧の切り替えは option に「オンのものだけ」を記録する作りなので、
 * ここで足しておけば、以後は管理画面から普通に外せる。
 */
function hb_auto_updates_seed(): void
{
    if (get_option(HB_AUTOUPDATE_MARKER)) {
        return;
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugins = hb_auto_updates_add('auto_update_plugins', array_keys(get_plugins()));
    $themes  = hb_auto_updates_add('auto_update_themes', array_keys(wp_get_themes()));

    // 本体のメジャー更新の扱いを、更新画面の表示にも合わせておく。
    if (!defined('WP_AUTO_UPDATE_CORE')) {
        update_site_option(
            'auto_update_core_major',
            hb_auto_updates_core_policy() === 'major' ? 'enabled' : 'disabled'
        );
    }

    update_option(HB_AUTOUPDATE_MARKER, [
        'at'      => time(),
        'version' => HB_BASE_VERSION,
        'plugins' => $plugins,
        'themes'  => $themes,
    ], false);
}

/**
 * 既存の一覧に足す。すでに入っているものには触らない。
 *
 * @param string[] $items
 * @return string[] 実際に足したもの
 */
function hb_auto_updates_add(string $option, array $items): array
{
    $current = get_site_option($option, []);

    if (!is_array($current)) {
        $current = [];
    }

    $added = array_values(array_diff($items, $current));

    if (!empty($added)) {
        update_site_option($option, array_values(array_unique(array_merge($current, $items))));
    }

    return $added;
}

/**
 * 新しく入れたプラグイン・テーマも自動更新の対象にする。
 *
 * 更新（upgrade）のときは何もしない。すでにある項目の設定を触る理由がないため。
 *
 * @param mixed $upgrader
 */
function hb_auto_updates_catch_new($upgrader, array $data): void
{
    if (($data['action'] ?? '') !== 'install') {
        return;
    }

    if (($data['type'] ?? '') === 'plugin') {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        hb_auto_updates_add('auto_update_plugins', array_keys(get_plugins()));

        return;
    }

    if (($data['type'] ?? '') === 'theme') {
        hb_auto_updates_add('auto_update_themes', array_keys(wp_get_themes()));
    }
}

/** 状態の要約。管理画面の表示用。 */
function hb_auto_updates_summary(): string
{
    if (!hb_base_const('HB_AUTO_UPDATES', true)) {
        return '<em>停止中</em>';
    }

    if (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) {
        return '<strong style="color:#b32d2e">サーバー側で自動更新が止められています</strong>'
            . '（<code>AUTOMATIC_UPDATER_DISABLED</code>）';
    }

    $plugins = get_site_option('auto_update_plugins', []);
    $themes  = get_site_option('auto_update_themes', []);

    $core = [
        'minor' => '本体はマイナー更新のみ',
        'major' => '本体はメジャー更新まで',
        'off'   => '本体は更新しない',
    ][hb_auto_updates_core_policy()];

    if (defined('WP_AUTO_UPDATE_CORE')) {
        $core = '本体は <code>WP_AUTO_UPDATE_CORE</code> の指定に従う';
    }

    return sprintf(
        '%s ・ プラグイン %d 件 ・ テーマ %d 件',
        esc_html($core),
        is_array($plugins) ? count($plugins) : 0,
        is_array($themes) ? count($themes) : 0
    );
}
