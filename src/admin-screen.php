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
    add_action('admin_enqueue_scripts', 'hb_admin_screen_style');
}

/**
 * 操作ボタンを横に並べるためだけの1行。
 *
 * ボタンごとに <form> が要る（それぞれ別の操作をPOSTするため）が、
 * form は既定で改行されるので、表のセルの中で縦に積まれてしまう。
 */
function hb_admin_screen_style(string $hook): void
{
    if ($hook !== 'settings_page_hb-base') {
        return;
    }

    wp_add_inline_style('wp-admin', '.hb-inline-form{display:inline-block;margin-right:.25em}');
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
            $email = sanitize_email(wp_unslash((string) ($_POST['hb_email'] ?? '')));
            $ttl   = (int) ($_POST['hb_ttl'] ?? 1800);

            if (!array_key_exists((string) $ttl, hb_device_invite_durations())) {
                $ttl = 1800;
            }

            $token = hb_device_invite_create($label, $email, $ttl);
            $url   = hb_device_invite_url($token);

            // URLは発行直後の一度しか出せない。画面に渡すため短時間だけ預ける。
            set_transient('hb_last_invite_' . get_current_user_id(), $url, 10 * MINUTE_IN_SECONDS);

            $notice = 'invite-created';

            if ($email !== '') {
                $notice = hb_device_invite_mail($email, $label, $url, time() + $ttl)
                    ? 'invite-mailed'
                    : 'invite-mail-failed';
            }
            break;

        case 'invite-revoke':
            $id = isset($_POST['hb_id']) ? sanitize_key(wp_unslash($_POST['hb_id'])) : '';
            if ($id !== '') {
                hb_device_invite_revoke($id);
                $notice = 'invite-revoked';
            }
            break;

        case 'install-plugin':
            $slug   = isset($_POST['hb_slug']) ? sanitize_key(wp_unslash($_POST['hb_slug'])) : '';
            $result = hb_required_plugins_install($slug);
            $notice = is_wp_error($result) ? 'plugin-failed' : 'plugin-installed';
            if (is_wp_error($result)) {
                set_transient('hb_plugin_error_' . get_current_user_id(), $result->get_error_message(), 60);
            }
            break;

        case 'install-plugins':
            $result = hb_required_plugins_install_all();
            $notice = empty($result['failed']) ? 'plugin-installed' : 'plugin-failed';
            if (!empty($result['failed'])) {
                set_transient(
                    'hb_plugin_error_' . get_current_user_id(),
                    implode(' / ', array_map(
                        static function ($slug, $message) { return $slug . ': ' . $message; },
                        array_keys($result['failed']),
                        $result['failed']
                    )),
                    60
                );
            }
            break;

        case 'restore-plugin':
            $slug = isset($_POST['hb_slug']) ? sanitize_key(wp_unslash($_POST['hb_slug'])) : '';
            if ($slug !== '') {
                hb_required_plugins_clear_optout($slug);
                $notice = 'plugin-restored';
            }
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

/* ---------------------------------------------------------------------------
 * 表示
 *
 * WordPressの設定画面の作法に合わせる。
 *   見出し        … <h2>
 *   説明          … <p class="description">
 *   状態・入力欄  … <table class="form-table">
 *   一覧          … <table class="wp-list-table widefat striped"> ＋ <thead>
 * 独自のCSSは足さない。コアの見た目が変わっても一緒に追従するため。
 * -------------------------------------------------------------------------*/

function hb_admin_screen_render(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>HBベース</h1>';

    hb_admin_screen_notice();

    // 節ごとにカードで囲む。見出しだけだと、どこからどこまでが
    // ひとつの話なのか分からなくなる（WordPress標準のpostboxを使う）。
    hb_admin_screen_card('状態', 'hb_admin_screen_status');
    hb_admin_screen_card('端末の許可', 'hb_admin_screen_devices');
    hb_admin_screen_card('標準プラグイン', 'hb_admin_screen_plugins');
    hb_admin_screen_card('遮断した要求', 'hb_admin_screen_guard_log');

    echo '</div>';
}

/**
 * 節をカードで囲む。
 * 中身が何も出なかった節はカードごと出さない（空の箱が並ぶのを避ける）。
 */
function hb_admin_screen_card(string $title, callable $render): void
{
    ob_start();
    $render();
    $inside = (string) ob_get_clean();

    if (trim($inside) === '') {
        return;
    }

    printf(
        '<div class="postbox"><div class="postbox-header"><h2 class="hndle">%s</h2></div><div class="inside">%s</div></div>',
        esc_html($title),
        $inside // 各描画関数でエスケープ済み
    );
}

/* --- 状態 ------------------------------------------------------------- */

function hb_admin_screen_status(): void
{
    echo '<table class="form-table" role="presentation"><tbody>';

    hb_admin_screen_row('バージョン', esc_html(HB_BASE_VERSION));
    hb_admin_screen_row('あなたのIP', '<code>' . esc_html(hb_client_ip()) . '</code>');
    hb_admin_screen_row(
        'この端末',
        hb_device_current_id() !== ''
            ? '<code>' . esc_html(substr(hb_device_current_id(), 0, 8)) . '</code>'
            : '<span class="description">未発行</span>'
    );
    hb_admin_screen_row('管理画面ガード', hb_admin_screen_mode_label(hb_admin_guard_mode()));
    hb_admin_screen_row(
        '許可IP',
        hb_admin_allow_ips()
            ? '<code>' . esc_html(implode(', ', hb_admin_allow_ips())) . '</code>'
            : '<span class="description">未設定（端末の許可だけで運用）</span>'
    );
    hb_admin_screen_row(
        'ログインURL',
        hb_login_slug() !== ''
            ? '<code>' . esc_html(home_url('/' . hb_login_slug() . '/')) . '</code>'
            : '<span class="description">既定（wp-login.php）</span>'
    );
    hb_admin_screen_row(
        'Turnstile',
        hb_turnstile_configured()
            ? '設定済み（' . esc_html(implode(', ', hb_turnstile_forms())) . '）'
            : '<span class="description">未設定</span>'
    );
    hb_admin_screen_row('自動更新', hb_auto_updates_summary());
    hb_admin_screen_row('メール', hb_mail_summary());
    hb_admin_screen_row(
        '保守担当',
        hb_base_to_list(hb_base_const('HB_MAINTAINERS', ''))
            ? esc_html(implode(', ', hb_base_to_list(hb_base_const('HB_MAINTAINERS', ''))))
            : '<span class="description">未設定（全員クライアント扱い）</span>'
    );

    echo '</tbody></table>';
    echo '<p class="description">設定値は <code>wp-config.php</code> の定数で変更します。'
        . 'この画面からは変更できません。</p>';
}

function hb_admin_screen_row(string $label, string $value): void
{
    printf(
        '<tr><th scope="row">%s</th><td>%s</td></tr>',
        esc_html($label),
        $value // 呼び出し側でエスケープ済み
    );
}

function hb_admin_screen_mode_label(string $mode): string
{
    $labels = [
        'off' => '<strong>停止中</strong><p class="description">誰でも管理画面を開けます。</p>',
        'log' => '<strong>様子見</strong><p class="description">遮断せず記録だけ取ります。</p>',
        'on'  => '<strong>有効</strong><p class="description">許可IPと許可端末だけが入れます。</p>',
    ];

    return $labels[$mode] ?? esc_html($mode);
}

/* --- 通知 ------------------------------------------------------------- */

function hb_admin_screen_notice(): void
{
    if (empty($_GET['hb_notice'])) {
        return;
    }

    $key = sanitize_key((string) wp_unslash($_GET['hb_notice']));

    if ($key === 'invite-mail-failed') {
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . '招待リンクは発行できましたが、メールを送信できませんでした。下のURLを直接お渡しください。'
            . '</p></div>';
    }

    if ($key === 'plugin-failed') {
        $message = get_transient('hb_plugin_error_' . get_current_user_id());
        delete_transient('hb_plugin_error_' . get_current_user_id());

        printf(
            '<div class="notice notice-error is-dismissible"><p>導入に失敗しました。%s</p></div>',
            esc_html(is_string($message) ? $message : '')
        );

        return;
    }

    $messages = [
        'device-approved'  => 'この端末を許可しました。',
        'device-revoked'   => '許可を取り消しました。',
        'device-deleted'   => '記録を削除しました。',
        'invite-created'   => '招待リンクを発行しました。下のURLを本人に渡してください（この画面を離れると二度と表示できません）。',
        'invite-mailed'    => '招待リンクを発行し、メールを送信しました。',
        'invite-revoked'   => '招待リンクを取り消しました。',
        'log-cleared'      => '記録を消去しました。',
        'plugin-installed' => 'プラグインを導入しました。',
        'plugin-restored'  => '自動有効化の対象に戻しました。次に管理画面を開いたときに有効化されます。',
    ];

    if (isset($messages[$key])) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$key]));
    }
}

/* --- 端末の許可 --------------------------------------------------------- */

function hb_admin_screen_devices(): void
{
    echo '<p class="description">固定IPが無い回線からでも、ここで許可した端末だけは管理画面に入れます。</p>';

    hb_admin_screen_invite_box(get_transient('hb_last_invite_' . get_current_user_id()));

    $devices = hb_devices_all();

    echo '<h3>端末の一覧</h3>';

    if (empty($devices)) {
        echo '<p class="description">許可・申請のある端末はまだありません。</p>';

        return;
    }

    uasort($devices, static function ($a, $b) {
        return ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0);
    });

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">状態</th><th scope="col">端末</th><th scope="col">名前・理由</th>';
    echo '<th scope="col">IP</th><th scope="col">ブラウザ</th><th scope="col">日時</th><th scope="col">操作</th>';
    echo '</tr></thead><tbody>';

    $states = [
        'pending' => ['承認待ち', ['approve', 'delete']],
        'trusted' => ['許可済み', ['revoke']],
        'revoked' => ['取り消し済み', ['approve', 'delete']],
    ];

    foreach ($devices as $id => $device) {
        $state = (string) ($device['status'] ?? '');

        if (!isset($states[$state])) {
            continue;
        }

        [$label, $actions] = $states[$state];
        $when = (int) ($device['approved'] ?? $device['requested'] ?? $device['created'] ?? 0);

        echo '<tr>';
        printf('<td>%s</td>', esc_html($label));
        printf('<td><code>%s</code></td>', esc_html(substr($id, 0, 8)));
        printf(
            '<td>%s%s</td>',
            esc_html((string) ($device['label'] ?? '')) ?: '—',
            ($device['note'] ?? '') !== ''
                ? '<br><span class="description">' . esc_html((string) $device['note']) . '</span>'
                : ''
        );
        printf('<td><code>%s</code></td>', esc_html((string) ($device['ip'] ?? '')));
        printf('<td><span class="description">%s</span></td>', esc_html(mb_substr((string) ($device['ua'] ?? ''), 0, 60)));
        printf('<td>%s</td>', $when ? esc_html(wp_date('Y-m-d H:i', $when)) : '—');

        echo '<td>';
        foreach ($actions as $action) {
            hb_admin_screen_action_button($action, $id);
        }
        echo '</td></tr>';
    }

    echo '</tbody></table>';
}

function hb_admin_screen_invite_box($url): void
{
    echo '<h3>招待リンクを発行する</h3>';
    echo '<p class="description">発行したURLを、許可したい端末で開いてもらいます。'
        . '開いた端末だけが許可され、1回使うと無効になります。</p>';

    echo '<form method="post">';
    wp_nonce_field('hb_base_action');
    echo '<input type="hidden" name="hb_action" value="invite">';

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="hb_label">宛名（覚書）</label></th><td>';
    echo '<input type="text" id="hb_label" name="hb_label" class="regular-text" placeholder="例: 山田様 iPhone">';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="hb_email">メールで送る</label></th><td>';
    echo '<input type="email" id="hb_email" name="hb_email" class="regular-text" placeholder="任意">';
    echo '<p class="description">入力するとこの宛先に送ります。空ならURLを画面に出すだけです。</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="hb_ttl">有効期限</label></th><td>';
    echo '<select id="hb_ttl" name="hb_ttl">';
    foreach (hb_device_invite_durations() as $seconds => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($seconds),
            selected($seconds, '86400', false),
            esc_html($label)
        );
    }
    echo '</select>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button('発行する', 'secondary', 'submit', false);
    echo '</form>';

    if (is_string($url) && $url !== '') {
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">発行したURL</th><td>';
        printf(
            '<input type="text" readonly class="large-text code" onclick="this.select()" value="%s">',
            esc_attr($url)
        );
        echo '<p class="description">記録には残らないので、この画面を離れると二度と表示できません。</p>';
        echo '</td></tr></tbody></table>';
    }

    hb_admin_screen_invite_list();
}

/** 発行済みの招待リンク。 */
function hb_admin_screen_invite_list(): void
{
    $invites = hb_device_invites_all();

    if (empty($invites)) {
        return;
    }

    uasort($invites, static function ($a, $b) {
        return ($b['created'] ?? 0) <=> ($a['created'] ?? 0);
    });

    $states = [
        'open'    => '未使用',
        'used'    => '使用済み',
        'expired' => '期限切れ',
    ];

    echo '<h3>発行済みの招待リンク</h3>';
    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">状態</th><th scope="col">宛名</th><th scope="col">送り先</th>';
    echo '<th scope="col">発行</th><th scope="col">期限</th><th scope="col">使った端末</th><th scope="col">操作</th>';
    echo '</tr></thead><tbody>';

    foreach ($invites as $id => $invite) {
        $state = hb_device_invite_state($invite);

        echo '<tr>';
        printf('<td>%s</td>', esc_html($states[$state]));
        printf('<td>%s</td>', esc_html((string) ($invite['label'] ?? '')) ?: '—');
        printf('<td>%s</td>', esc_html((string) ($invite['email'] ?? '')) ?: '—');
        printf('<td>%s</td>', esc_html(wp_date('n/j H:i', (int) $invite['created'])));
        printf('<td>%s</td>', esc_html(wp_date('n/j H:i', (int) $invite['expires'])));
        printf('<td>%s</td>', ($invite['device'] ?? '') !== ''
            ? '<code>' . esc_html((string) $invite['device']) . '</code>'
            : '—');

        echo '<td>';
        if ($state === 'open') {
            echo '<form method="post" class="hb-inline-form">';
            wp_nonce_field('hb_base_action');
            echo '<input type="hidden" name="hb_action" value="invite-revoke">';
            printf('<input type="hidden" name="hb_id" value="%s">', esc_attr($id));
            echo '<button type="submit" class="button">取り消す</button>';
            echo '</form>';
        } else {
            echo '—';
        }
        echo '</td></tr>';
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

    echo '<form method="post" class="hb-inline-form">';
    wp_nonce_field('hb_base_action');
    printf('<input type="hidden" name="hb_action" value="%s">', esc_attr($action));
    printf('<input type="hidden" name="hb_id" value="%s">', esc_attr($id));
    printf('<button type="submit" class="button %s">%s</button>', esc_attr($class), esc_html($label));
    echo '</form> ';
}

/* --- 標準プラグイン ------------------------------------------------------ */

function hb_admin_screen_plugins(): void
{
    if (!current_user_can('install_plugins')) {
        return;
    }

    $status  = hb_required_plugins_status();
    $optout  = hb_required_plugins_optout();
    $missing = array_filter($status, static function ($info) { return $info['state'] === 'missing'; });

    echo '<p class="description">未導入のものは裏で自動的に導入します。'
        . '入っているのに停止しているものは自動で有効化し、クライアントには「停止」リンクを出しません。</p>';

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">プラグイン</th><th scope="col">スラッグ</th><th scope="col">状態</th><th scope="col">操作</th>';
    echo '</tr></thead><tbody>';

    foreach ($status as $slug => $info) {
        $excluded = in_array($slug, $optout, true);

        $labels = [
            'active'   => '有効',
            'inactive' => $excluded ? '停止中（自動有効化の対象外）' : '停止中',
            'missing'  => '未導入',
        ];

        echo '<tr>';
        printf('<td>%s</td>', esc_html($info['name']));
        printf('<td><code>%s</code></td>', esc_html($slug));
        printf('<td>%s</td>', esc_html($labels[$info['state']]));

        echo '<td>';
        if ($info['state'] === 'missing') {
            hb_admin_screen_plugin_button('install-plugin', $slug, 'いますぐ導入');
        } elseif ($excluded) {
            hb_admin_screen_plugin_button('restore-plugin', $slug, '自動有効化に戻す');
        } elseif ($info['state'] === 'inactive') {
            hb_admin_screen_plugin_button('install-plugin', $slug, '有効化');
        } else {
            echo '—';
        }
        echo '</td></tr>';
    }

    echo '</tbody></table>';

    if (!empty($missing)) {
        echo '<form method="post">';
        wp_nonce_field('hb_base_action');
        echo '<input type="hidden" name="hb_action" value="install-plugins">';
        submit_button(sprintf('足りない %d 本をまとめて導入', count($missing)), 'secondary', 'submit', false);
        echo '</form>';
    }

    echo '<p class="description">一覧は <code>HB_REQUIRED_PLUGINS</code> で差し替えられます。'
        . 'WordPress.org にあるものだけが対象です（ACF PRO などの有料プラグインは扱えません）。</p>';
}

function hb_admin_screen_plugin_button(string $action, string $slug, string $label): void
{
    echo '<form method="post" class="hb-inline-form">';
    wp_nonce_field('hb_base_action');
    printf('<input type="hidden" name="hb_action" value="%s">', esc_attr($action));
    printf('<input type="hidden" name="hb_slug" value="%s">', esc_attr($slug));
    printf('<button type="submit" class="button">%s</button>', esc_html($label));
    echo '</form>';
}

/* --- 遮断の記録 --------------------------------------------------------- */

function hb_admin_screen_guard_log(): void
{
    $log = get_option(HB_GUARD_LOG_OPTION, []);

    if (!is_array($log) || empty($log)) {
        return;
    }

    echo '<p class="description">様子見モードでは、遮断したはずの要求がここに記録されます。'
        . '身内のIPや端末が並んでいないか確認してから有効にしてください。</p>';

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">日時</th><th scope="col">IP</th><th scope="col">URL</th>';
    echo '<th scope="col">端末</th><th scope="col">ブラウザ</th>';
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

    echo '<form method="post">';
    wp_nonce_field('hb_base_action');
    echo '<input type="hidden" name="hb_action" value="clear-log">';
    submit_button('記録を消去', 'secondary', 'submit', false);
    echo '</form>';
}
