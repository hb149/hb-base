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
    $hook = add_options_page(
        'HBベース',
        'HBベース',
        'manage_options',
        'hb-base',
        'hb_admin_screen_render'
    );

    // メタボックスの登録は画面が決まってから。
    add_action('load-' . $hook, 'hb_admin_screen_boxes');
}

/**
 * 節をメタボックスとして登録する。
 *
 * postbox のHTMLを自分で書くと、折りたたみも並べ替えも表示オプションも
 * 付いてこない。WordPress の仕組みに乗せれば全部ついてくる。
 */
function hb_admin_screen_boxes(): void
{
    $screen = get_current_screen();

    if (!$screen) {
        return;
    }

    $boxes = [
        'hb-status'  => ['状態', 'hb_admin_screen_status'],
        'hb-devices' => ['端末の許可', 'hb_admin_screen_devices'],
    ];

    if (current_user_can('install_plugins')) {
        $boxes['hb-plugins'] = ['標準プラグイン', 'hb_admin_screen_plugins'];
    }

    // 記録が無いうちは箱だけ並んでも仕方がないので出さない。
    $log = get_option(HB_GUARD_LOG_OPTION, []);

    if (is_array($log) && !empty($log)) {
        $boxes['hb-log'] = ['遮断した要求', 'hb_admin_screen_guard_log'];
    }

    foreach ($boxes as $id => [$title, $callback]) {
        add_meta_box($id, $title, $callback, $screen, 'normal');
    }

    // 折りたたみの開閉を覚えさせる。
    add_screen_option('layout_columns', ['max' => 1, 'default' => 1]);
    wp_enqueue_script('postbox');
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

        case 'invite-delete':
            $id = isset($_POST['hb_id']) ? sanitize_key(wp_unslash($_POST['hb_id'])) : '';
            if ($id !== '') {
                hb_device_invite_delete($id);
                $notice = 'invite-deleted';
            }
            break;

        case 'invite-restore':
            $id = isset($_POST['hb_id']) ? sanitize_key(wp_unslash($_POST['hb_id'])) : '';
            if ($id !== '') {
                hb_device_invite_restore($id);
                $notice = 'invite-restored';
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

    $screen = get_current_screen();

    echo '<div class="wrap">';
    echo '<h1>HBベース</h1>';

    hb_admin_screen_notice();

    // メタボックスを置くための器。WordPress の管理画面が前提にしている構造。
    echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-1"><div id="postbox-container-1" class="postbox-container">';

    do_meta_boxes($screen, 'normal', null);

    echo '</div></div><br class="clear"></div>';

    wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', false);
    wp_nonce_field('meta-box-order', 'meta-box-order-nonce', false);

    echo '</div>';
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
        'invite-deleted'   => '招待の記録を削除しました。',
        'invite-restored'  => '招待リンクを有効に戻しました。相手の手元にあるURLがそのまま使えます。',
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

    // 操作は「いまの状態をどう変えるか」で書く。
    // プラグイン一覧の「自動更新を有効化／無効化」と同じ考え方。
    $delete = ['delete' => ['label' => '削除', 'confirm' => 'この端末の記録を削除します。よろしいですか。']];

    $states = [
        'trusted' => ['有効', ['revoke'  => '無効にする'] + $delete],
        'revoked' => ['無効', ['approve' => '有効にする'] + $delete],
        'pending' => ['承認待ち', ['approve' => '有効にする'] + $delete],
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
        hb_admin_screen_row_actions($actions, 'hb_id', $id);
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
        'open'    => '有効',
        'used'    => '使用済み',
        'revoked' => '取り消し済み',
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

        if ($state === 'open') {
            $actions = ['invite-revoke' => '取り消す'];
        } elseif ($state === 'used') {
            // 使ったリンクは戻さない。1回限りという約束を崩さないため。
            $actions = [];
        } else {
            $actions = ['invite-restore' => '有効に戻す'];
        }

        $actions['invite-delete'] = ['label' => '削除', 'confirm' => 'この招待の記録を削除します。よろしいですか。'];

        echo '<td>';
        hb_admin_screen_row_actions($actions, 'hb_id', $id);
        echo '</td></tr>';
    }

    echo '</tbody></table>';
}

/**
 * 行の操作ボタン。
 *
 * 操作ごとにフォームを分けると、form が改行されてボタンが縦に積まれる。
 * ボタン自身が name と value を送れるので、フォームは1つで足りる。
 * （こうすると横並びのためのCSSが要らない）
 *
 * @param array<string,string> $actions 操作 => ラベル
 */
function hb_admin_screen_row_actions(array $actions, string $field, string $value): void
{
    if (empty($actions)) {
        echo '—';

        return;
    }

    echo '<form method="post">';
    wp_nonce_field('hb_base_action');
    printf('<input type="hidden" name="%s" value="%s">', esc_attr($field), esc_attr($value));

    foreach ($actions as $action => $spec) {
        $label   = is_array($spec) ? (string) $spec['label'] : (string) $spec;
        $confirm = is_array($spec) ? (string) ($spec['confirm'] ?? '') : '';

        printf(
            '<button type="submit" name="hb_action" value="%s" class="button"%s>%s</button> ',
            esc_attr($action),
            // 取り返しのつかない操作だけ一度止める。コアも削除系で同じやり方をしている。
            $confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '',
            esc_html($label)
        );
    }

    echo '</form>';
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

        if ($info['state'] === 'missing') {
            $actions = ['install-plugin' => 'いますぐ導入'];
        } elseif ($excluded) {
            $actions = ['restore-plugin' => '自動有効化に戻す'];
        } elseif ($info['state'] === 'inactive') {
            $actions = ['install-plugin' => '有効化'];
        } else {
            $actions = [];
        }

        echo '<td>';
        hb_admin_screen_row_actions($actions, 'hb_slug', $slug);
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
