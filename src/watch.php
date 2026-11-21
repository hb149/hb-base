<?php
/**
 * 気づけるようにする
 *
 * 防ぐ仕掛けは入れたが、抜けられたときに気づけないと意味がない。
 * 保守担当（HB_MAINTAINERS）に知らせる。
 *
 * ■ 管理者が増えたとき
 *   侵入後の常套手段は、管理者アカウントを1つ作って居座ること。
 *   パスワードを変えてもログインURLを変えても、その口は残る。
 *   増えた瞬間に分かるようにしておく。
 *
 * ■ 見慣れない端末からログインされたとき
 *   端末の見分けは管理画面ガードと同じ仕組みを使う。
 *
 * ■ WordPress が出す運用メールの宛先
 *   既定ではすべて admin_email（＝クライアント）に飛ぶ。
 *   PHPの致命的エラーも自動更新の結果も、クライアントが読んで分かるものではないし、
 *   こちらには届かない。逆なので、保守担当へ回す。
 *
 * 止めるとき:
 *   define('HB_WATCH', false);
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_watch_init(): void
{
    if (!hb_base_const('HB_WATCH', true)) {
        return;
    }

    // 管理者が増えた。
    // 新規作成でも set_user_role は必ず通るので、user_register は見ない
    // （両方見ると作成時に2通出る）。
    add_action('set_user_role', 'hb_watch_role_change', 10, 3);
    add_action('add_user_role', 'hb_watch_role_added', 10, 2);
    add_action('grant_super_admin', 'hb_watch_super_admin');

    // 見慣れない端末からのログイン
    add_action('wp_login', 'hb_watch_login', 10, 2);

    // 運用メールの宛先
    add_filter('recovery_mode_email', 'hb_watch_recovery_mail');
    add_filter('auto_core_update_email', 'hb_watch_update_mail');
    add_filter('auto_plugin_theme_update_email', 'hb_watch_update_mail');
    add_filter('automatic_updates_debug_email', 'hb_watch_update_mail');
}

/* ---------------------------------------------------------------------------
 * 宛先
 * -------------------------------------------------------------------------*/

/**
 * 知らせる先。保守担当が未設定なら管理者アドレス（＝従来どおり）。
 *
 * @return string[]
 */
function hb_watch_recipients(): array
{
    $list = [];

    foreach (hb_base_to_list(hb_base_const('HB_MAINTAINERS', '')) as $who) {
        if (is_email($who)) {
            $list[] = $who;
            continue;
        }

        $user = ctype_digit($who) ? get_user_by('id', (int) $who) : get_user_by('login', $who);

        if ($user && is_email($user->user_email)) {
            $list[] = $user->user_email;
        }
    }

    if (empty($list)) {
        $list[] = (string) get_option('admin_email');
    }

    return array_values(array_unique(array_filter($list)));
}

function hb_watch_send(string $subject, array $lines): void
{
    $site = wp_specialchars_decode((string) get_option('blogname'));

    $lines[] = '';
    $lines[] = 'サイト : ' . $site . '（' . home_url('/') . '）';
    $lines[] = '日時   : ' . wp_date('Y-m-d H:i');

    wp_mail(hb_watch_recipients(), sprintf('[%s] %s', $site, $subject), implode("\n", $lines));
}

/* ---------------------------------------------------------------------------
 * 管理者が増えた
 * -------------------------------------------------------------------------*/

/**
 * @param string[] $old_roles
 */
function hb_watch_role_change(int $user_id, string $role, $old_roles = []): void
{
    if ($role !== 'administrator' || in_array('administrator', (array) $old_roles, true)) {
        return;
    }

    $user = get_user_by('id', $user_id);

    if ($user) {
        hb_watch_admin_added($user, empty($old_roles) ? '新規登録' : '権限の変更');
    }
}

/** 既存のユーザーに管理者権限を足した場合（set_user_role は通らない）。 */
function hb_watch_role_added(int $user_id, string $role): void
{
    if ($role !== 'administrator') {
        return;
    }

    $user = get_user_by('id', $user_id);

    if ($user) {
        hb_watch_admin_added($user, '権限の追加');
    }
}

function hb_watch_super_admin(int $user_id): void
{
    $user = get_user_by('id', $user_id);

    if ($user) {
        hb_watch_admin_added($user, 'ネットワーク管理者への昇格');
    }
}

/**
 * 管理者が増えたことを知らせる。
 *
 * WordPress は役割の変更で set_user_role と add_user_role の両方を撃つことがあり、
 * 素直に書くと1回の操作で2通出る。同じ利用者について1リクエストに1回だけにする。
 *
 * @param WP_User $user
 */
function hb_watch_admin_added($user, string $how): void
{
    static $notified = [];

    if (isset($notified[$user->ID])) {
        return;
    }

    $notified[$user->ID] = true;

    hb_watch_send('管理者が増えました', [
        '管理者権限を持つユーザーが追加されました。心当たりが無ければ確認してください。',
        '',
        'ユーザー名 : ' . $user->user_login,
        'メール     : ' . $user->user_email,
        '経緯       : ' . $how,
        '操作した人 : ' . hb_watch_actor(),
        '接続元IP   : ' . hb_client_ip(),
        '',
        '確認する   : ' . admin_url('users.php?role=administrator'),
    ]);
}

function hb_watch_actor(): string
{
    $id = get_current_user_id();

    if ($id === 0) {
        return '（ログインしていない処理）';
    }

    $user = get_user_by('id', $id);

    return $user ? $user->user_login : (string) $id;
}

/* ---------------------------------------------------------------------------
 * 見慣れない端末からのログイン
 * -------------------------------------------------------------------------*/

/**
 * @param string  $login
 * @param WP_User $user
 */
function hb_watch_login($login, $user): void
{
    // 知らせる価値があるのは、サイトを壊せる権限のログインだけ。
    if (!($user instanceof WP_User) || !user_can($user, 'manage_options')) {
        return;
    }

    $device = hb_device_ensure_id();
    $seen   = get_user_meta($user->ID, 'hb_login_devices', true);
    $seen   = is_array($seen) ? $seen : [];

    if (in_array($device, $seen, true)) {
        return;
    }

    // 覚えておく。増え続けないよう直近20件まで。
    $seen[] = $device;
    update_user_meta($user->ID, 'hb_login_devices', array_slice($seen, -20));

    // 1台目は「初めて記録した」だけなので知らせない。
    if (count($seen) === 1) {
        return;
    }

    hb_watch_send('見慣れない端末からログインがありました', [
        'これまでに記録の無い端末から管理者がログインしました。',
        '心当たりが無ければ、パスワードの変更と端末の確認をしてください。',
        '',
        'ユーザー名 : ' . $user->user_login,
        '端末       : ' . substr($device, 0, 8),
        '接続元IP   : ' . hb_client_ip(),
        'ブラウザ   : ' . hb_device_ua(),
        '',
        '端末の管理 : ' . admin_url('options-general.php?page=hb-base&tab=devices'),
    ]);
}

/* ---------------------------------------------------------------------------
 * 運用メールの宛先
 * -------------------------------------------------------------------------*/

/**
 * 復旧モード（PHPの致命的エラー）の通知。
 *
 * @param array $email
 */
function hb_watch_recovery_mail($email): array
{
    $email = is_array($email) ? $email : [];

    $email['to'] = hb_watch_recipients();

    return $email;
}

/**
 * 自動更新の結果通知。
 *
 * @param array $email
 */
function hb_watch_update_mail($email): array
{
    $email = is_array($email) ? $email : [];

    $email['to'] = hb_watch_recipients();

    return $email;
}
