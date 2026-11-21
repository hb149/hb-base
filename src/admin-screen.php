<?php
/**
 * 管理画面
 *
 * 設定値そのものは wp-config.php の定数が正なので、ここでは変更できない。
 * ここでやるのは「今どういう状態か」の確認と、端末の許可／取り消しだけ。
 *
 * 画面の体裁はWP標準のクラスだけで作る（独自CSSを足さない）。
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_admin_screen_init(): void
{
    add_action('admin_menu', 'hb_admin_screen_menu');
    add_action('admin_init', 'hb_admin_screen_handle_actions');
    add_action('admin_notices', 'hb_admin_screen_pending_notice');
}

function hb_admin_screen_menu(): void
{
    add_options_page(
        'HBベース',
        'HBベース',
        'manage_options',
        'hb-base',
        'hb_admin_screen_render'
    );
}

/** 承認待ちがあることを全管理画面で知らせる。見落とすとクライアントが入れないままになる。 */
function hb_admin_screen_pending_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if ($screen && $screen->id === 'settings_page_hb-base') {
        return;
    }

    $pending = hb_admin_screen_devices_by_status('pending');

    if (empty($pending)) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>管理画面へのアクセス申請が %d 件あります。<a href="%s">確認する</a></p></div>',
        count($pending),
        esc_url(admin_url('options-general.php?page=hb-base'))
    );
}

function hb_admin_screen_devices_by_status(string $status): array
{
    $out = [];

    foreach (hb_devices_all() as $id => $device) {
        if (($device['status'] ?? '') === $status) {
            $out[$id] = $device;
        }
    }

    return $out;
}

/* ---------------------------------------------------------------------------
 * 操作
 * -------------------------------------------------------------------------*/

function hb_admin_screen_handle_actions(): void
{
    if (!isset($_POST['hb_action']) || !current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('hb_base_action');

    $action = sanitize_key((string) wp_unslash($_POST['hb_action']));
    $id     = isset($_POST['hb_id']) ? sanitize_text_field(wp_unslash((string) $_POST['hb_id'])) : '';
    $notice = '';

    switch ($action) {
        case 'approve':
            if ($id !== '') {
                $device = hb_device_get($id);
                hb_device_trust($id, (string) ($device['label'] ?? ''), 'approved');
                $notice = 'device-approved';
            }
            break;

        case 'revoke':
            if ($id !== '') {
                hb_device_revoke($id);
                $notice = 'device-revoked';
            }
            break;

        case 'delete':
            if ($id !== '') {
                hb_device_delete($id);
                $notice = 'device-deleted';
            }
            break;

        case 'invite':
            $label = sanitize_text_field(wp_unslash((string) ($_POST['hb_label'] ?? '')));
            $token = hb_device_invite_create($label);
            set_transient('hb_last_invite_' . get_current_user_id(), $token, 1800);
            $notice = 'invite-created';
            break;

        case 'clear-log':
            delete_option(HB_GUARD_LOG_OPTION);
            $notice = 'log-cleared';
            break;
    }

    wp_safe_redirect(add_query_arg('hb_notice', $notice, admin_url('options-general.php?page=hb-base')));
    exit;
}

/* ---------------------------------------------------------------------------
 * 表示
 * -------------------------------------------------------------------------*/

function hb_admin_screen_render(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $mode    = hb_admin_guard_mode();
    $devices = hb_devices_all();
    $invite  = get_transient('hb_last_invite_' . get_current_user_id());

    echo '<div class="wrap">';
    echo '<h1>HBベース</h1>';

    hb_admin_screen_notice();

    /* --- 状態 --- */
    echo '<h2>状態</h2>';
    echo '<table class="widefat striped" style="max-width:52rem"><tbody>';
    hb_admin_screen_row('バージョン', esc_html(HB_BASE_VERSION));
    hb_admin_screen_row('あなたのIP', '<code>' . esc_html(hb_client_ip()) . '</code>');
    hb_admin_screen_row('この端末', '<code>' . esc_html(substr(hb_device_current_id(), 0, 8) ?: '未発行') . '</code>');
    hb_admin_screen_row('管理画面ガード', hb_admin_screen_mode_label($mode));
    hb_admin_screen_row(
        '許可IP',
        hb_admin_allow_ips()
            ? '<code>' . esc_html(implode(', ', hb_admin_allow_ips())) . '</code>'
            : '<em>未設定（端末の許可だけで運用）</em>'
    );
    hb_admin_screen_row(
        'Turnstile',
        hb_turnstile_configured()
            ? '設定済み（' . esc_html(implode(', ', hb_turnstile_forms())) . '）'
            : '<em>未設定</em>'
    );
    hb_admin_screen_row(
        'パスキー日本語化',
        hb_base_module_enabled('passkey_ja')
            ? (in_array(get_locale(), ['ja', 'ja_JP'], true) ? '有効' : '<em>サイトが日本語以外のため停止中</em>')
            : '<em>無効</em>'
    );
    echo '</tbody></table>';

    echo '<p class="description">設定値は <code>wp-config.php</code> の定数で変更します。'
        . 'この画面からは変更できません。</p>';

    /* --- 端末の許可 --- */
    echo '<h2>端末の許可</h2>';
    echo '<p class="description">固定IPが無い回線からでも、ここで許可した端末だけは管理画面に入れます。</p>';

    hb_admin_screen_invite_box($invite);
    hb_admin_screen_device_table('承認待ち', hb_admin_screen_devices_by_status('pending'), ['approve', 'delete']);
    hb_admin_screen_device_table('許可済み', hb_admin_screen_devices_by_status('trusted'), ['revoke']);
    hb_admin_screen_device_table('取り消し済み', hb_admin_screen_devices_by_status('revoked'), ['approve', 'delete']);

    /* --- 遮断の記録 --- */
    hb_admin_screen_guard_log();

    echo '</div>';
}

function hb_admin_screen_row(string $label, string $value): void
{
    printf(
        '<tr><th scope="row" style="width:12rem">%s</th><td>%s</td></tr>',
        esc_html($label),
        $value // 呼び出し側でエスケープ済み
    );
}

function hb_admin_screen_mode_label(string $mode): string
{
    $labels = [
        'off' => '<strong>停止中</strong>（誰でも管理画面を開ける）',
        'log' => '<strong>様子見</strong>（遮断せず記録だけ）',
        'on'  => '<strong>有効</strong>（許可IP・許可端末のみ）',
    ];

    return $labels[$mode] ?? esc_html($mode);
}

function hb_admin_screen_notice(): void
{
    if (empty($_GET['hb_notice'])) {
        return;
    }

    $messages = [
        'device-approved' => 'この端末を許可しました。',
        'device-revoked'  => '許可を取り消しました。',
        'device-deleted'  => '記録を削除しました。',
        'invite-created'  => '招待リンクを発行しました。下のURLを本人に渡してください。',
        'log-cleared'     => '記録を消去しました。',
    ];

    $key = sanitize_key((string) wp_unslash($_GET['hb_notice']));

    if (isset($messages[$key])) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$key]));
    }
}

function hb_admin_screen_invite_box($invite): void
{
    echo '<h3>招待リンクを発行する</h3>';
    echo '<p class="description">発行したURLを、許可したい端末で開いてもらいます。1回限り・30分で失効します。</p>';

    echo '<form method="post">';
    wp_nonce_field('hb_base_action');
    echo '<input type="hidden" name="hb_action" value="invite">';
    echo '<p><label>名前（覚書） <input type="text" name="hb_label" class="regular-text" placeholder="例: 山田様 iPhone"></label> ';
    submit_button('発行', 'secondary', 'submit', false);
    echo '</p>';
    echo '</form>';

    if (is_string($invite) && $invite !== '') {
        printf(
            '<p><label>招待URL<br><input type="text" readonly class="large-text code" onclick="this.select()" value="%s"></label></p>',
            esc_attr(hb_device_invite_url($invite))
        );
    }
}

function hb_admin_screen_device_table(string $title, array $devices, array $actions): void
{
    printf('<h3>%s（%d）</h3>', esc_html($title), count($devices));

    if (empty($devices)) {
        echo '<p class="description">該当なし</p>';

        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>端末</th><th>名前・理由</th><th>IP</th><th>ブラウザ</th><th>日時</th><th>操作</th>';
    echo '</tr></thead><tbody>';

    uasort($devices, static function ($a, $b) {
        return ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0);
    });

    foreach ($devices as $id => $device) {
        $when = (int) ($device['approved'] ?? $device['requested'] ?? $device['created'] ?? 0);

        echo '<tr>';
        printf('<td><code>%s</code></td>', esc_html(substr($id, 0, 8)));
        printf(
            '<td>%s%s</td>',
            esc_html((string) ($device['label'] ?? '')) ?: '<em>—</em>',
            ($device['note'] ?? '') !== '' ? '<br><span class="description">' . esc_html((string) $device['note']) . '</span>' : ''
        );
        printf('<td><code>%s</code></td>', esc_html((string) ($device['ip'] ?? '')));
        printf('<td><span class="description">%s</span></td>', esc_html(mb_substr((string) ($device['ua'] ?? ''), 0, 70)));
        printf('<td>%s</td>', $when ? esc_html(wp_date('Y-m-d H:i', $when)) : '—');

        echo '<td>';
        foreach ($actions as $action) {
            hb_admin_screen_action_button($action, $id);
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

function hb_admin_screen_action_button(string $action, string $id): void
{
    $labels = [
        'approve' => ['許可する', 'button-primary'],
        'revoke'  => ['取り消す', ''],
        'delete'  => ['削除', ''],
    ];

    if (!isset($labels[$action])) {
        return;
    }

    [$label, $class] = $labels[$action];

    echo '<form method="post" style="display:inline-block;margin-right:.25rem">';
    wp_nonce_field('hb_base_action');
    printf('<input type="hidden" name="hb_action" value="%s">', esc_attr($action));
    printf('<input type="hidden" name="hb_id" value="%s">', esc_attr($id));
    printf('<button type="submit" class="button %s">%s</button>', esc_attr($class), esc_html($label));
    echo '</form>';
}

function hb_admin_screen_guard_log(): void
{
    $log = get_option(HB_GUARD_LOG_OPTION, []);

    if (!is_array($log) || empty($log)) {
        return;
    }

    echo '<h2>遮断した要求</h2>';
    echo '<p class="description">様子見モードでは、遮断したはずの要求がここに記録されます。'
        . '身内のIPが並んでいないか確認してから有効にしてください。</p>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>日時</th><th>IP</th><th>URL</th><th>端末</th><th>ブラウザ</th>';
    echo '</tr></thead><tbody>';

    foreach (array_slice($log, 0, 30) as $row) {
        echo '<tr>';
        printf('<td>%s</td>', esc_html(wp_date('Y-m-d H:i', (int) ($row['time'] ?? 0))));
        printf('<td><code>%s</code></td>', esc_html((string) ($row['ip'] ?? '')));
        printf('<td>%s</td>', esc_html((string) ($row['path'] ?? '')));
        printf('<td><code>%s</code></td>', esc_html((string) ($row['device'] ?? '')));
        printf('<td><span class="description">%s</span></td>', esc_html((string) ($row['ua'] ?? '')));
        echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:.5rem">';
    wp_nonce_field('hb_base_action');
    echo '<input type="hidden" name="hb_action" value="clear-log">';
    submit_button('記録を消去', 'secondary', 'submit', false);
    echo '</form>';
}
