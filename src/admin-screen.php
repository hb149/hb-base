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
    add_action('admin_enqueue_scripts', 'hb_admin_screen_script');
    add_action('wp_ajax_hb_row_action', 'hb_admin_screen_ajax');
}

/* ---------------------------------------------------------------------------
 * その場で反映する（AJAX）
 *
 * 行の操作のたびに画面全体を読み直すと、どこを押したのか見失う。
 * 押した行だけ差し替える。
 *
 * 行のHTMLはPHPが返す。JS側で状態からラベルを組み立てると、
 * 同じ判断が2か所に分かれて必ずずれる。
 *
 * JSが動かない環境では、ボタンは普通のフォーム送信として働く。
 * -------------------------------------------------------------------------*/

function hb_admin_screen_script(string $hook): void
{
    if ($hook !== 'settings_page_hb-base') {
        return;
    }

    wp_register_script('hb-admin-screen', false, [], HB_BASE_VERSION, true);
    wp_enqueue_script('hb-admin-screen');

    $data = [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('hb_row_action'),
    ];

    wp_add_inline_script(
        'hb-admin-screen',
        '(' . hb_admin_screen_js() . ')(' . wp_json_encode($data) . ');'
    );
}

function hb_admin_screen_js(): string
{
    return <<<'JS'
function (cfg) {
  document.addEventListener('click', function (event) {
    var button = event.target.closest('button[data-hb-ajax]');
    if (!button) { return; }

    var row = button.closest('tr[data-hb-row]');
    if (!row) { return; }

    var confirmText = button.getAttribute('data-hb-confirm');
    if (confirmText && !window.confirm(confirmText)) { event.preventDefault(); return; }

    event.preventDefault();

    var body = new URLSearchParams({
      action: 'hb_row_action',
      nonce: cfg.nonce,
      hb_row_action: button.value,
      hb_row: row.getAttribute('data-hb-row')
    });

    row.style.opacity = '.5';
    row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

    fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) { location.reload(); return; }

        if (res.data.row) {
          row.outerHTML = res.data.row;
        } else {
          row.remove();
        }

        if (res.data.message) { hbNotice(res.data.message, res.data.type || 'success'); }
      })
      .catch(function () { location.reload(); });
  });

  // 画面上部に出す。WordPressの通知と同じ見た目になる。
  function hbNotice(text, type) {
    var wrap = document.querySelector('.wrap');
    var h1   = wrap ? wrap.querySelector('h1') : null;
    if (!h1) { return; }

    var old = wrap.querySelector('.hb-ajax-notice');
    if (old) { old.remove(); }

    var box = document.createElement('div');
    box.className = 'notice notice-' + type + ' is-dismissible hb-ajax-notice';
    box.innerHTML = '<p></p>';
    box.querySelector('p').textContent = text;
    h1.insertAdjacentElement('afterend', box);
  }
}
JS;
}

/**
 * 「操作|対象」を分解する。
 *
 * @return array{0:string,1:string}
 */
function hb_admin_screen_parse_row_action(string $raw): array
{
    $parts = explode('|', $raw, 2);

    return [sanitize_key($parts[0]), isset($parts[1]) ? sanitize_text_field($parts[1]) : ''];
}

/** 行の操作をその場で処理して、差し替えるHTMLを返す。 */
function hb_admin_screen_ajax(): void
{
    check_ajax_referer('hb_row_action', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    $notice = hb_admin_screen_do_action('');
    $row    = sanitize_text_field((string) wp_unslash($_POST['hb_row'] ?? ''));

    wp_send_json_success([
        'row'     => hb_admin_screen_rebuild_row($row),
        'message' => hb_admin_screen_message($notice),
        'type'    => strpos($notice, 'failed') !== false ? 'error' : 'success',
    ]);
}

/**
 * 差し替える行のHTML。対象が消えていれば空文字（＝行ごと消す）。
 */
function hb_admin_screen_rebuild_row(string $row): string
{
    if (strpos($row, 'device-') === 0) {
        $id     = substr($row, strlen('device-'));
        $device = hb_device_get($id);

        return $device === null ? '' : hb_admin_screen_device_row($id, $device);
    }

    if (strpos($row, 'invite-') === 0) {
        $id      = substr($row, strlen('invite-'));
        $invites = hb_device_invites_all();

        return isset($invites[$id]) ? hb_admin_screen_invite_row($id, $invites[$id]) : '';
    }

    if (strpos($row, 'plugin-') === 0) {
        $slug   = substr($row, strlen('plugin-'));
        $status = hb_required_plugins_status();

        return isset($status[$slug]) ? hb_admin_screen_plugin_row($slug, $status[$slug]) : '';
    }

    return '';
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
    // admin_init は admin-ajax.php でも走る。
    // ここで処理してリダイレクトすると、AJAXの応答がJSONでなくなる。
    if (wp_doing_ajax()) {
        return;
    }

    if (!isset($_POST['hb_action']) && !isset($_POST['hb_row_action'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('hb_base_action');

    $notice = hb_admin_screen_do_action(sanitize_key((string) wp_unslash($_POST['hb_action'] ?? '')));

    $tab = isset($_POST['hb_tab']) ? sanitize_key(wp_unslash($_POST['hb_tab'])) : '';

    wp_safe_redirect(add_query_arg(
        array_filter(['page' => 'hb-base', 'tab' => $tab, 'hb_notice' => $notice]),
        admin_url('options-general.php')
    ));
    exit;
}

/**
 * 操作の実処理。
 *
 * 画面からのPOSTと、行だけ差し替えるAJAXの両方から呼ぶ。
 * どちらも同じ道を通るので、片方だけ挙動がずれることがない。
 *
 * @return string 通知のキー
 */
function hb_admin_screen_do_action(string $action): string
{
    $id     = isset($_POST['hb_id']) ? sanitize_text_field(wp_unslash((string) $_POST['hb_id'])) : '';
    $notice = '';

    // 行のボタンは「操作|対象」の形で1つの値として送られてくる。
    if ($action === '' && isset($_POST['hb_row_action'])) {
        [$action, $id] = hb_admin_screen_parse_row_action((string) wp_unslash($_POST['hb_row_action']));
    }

    // プラグインの行は対象がスラッグ。どちらの経路でも同じ変数で受ける。
    $slug = isset($_POST['hb_slug']) ? sanitize_key(wp_unslash((string) $_POST['hb_slug'])) : sanitize_key($id);

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
            if ($id !== '') {
                hb_device_invite_delete($id);
                $notice = 'invite-deleted';
            }
            break;

        case 'invite-restore':
            if ($id !== '') {
                hb_device_invite_restore($id);
                $notice = 'invite-restored';
            }
            break;

        case 'invite-revoke':
            if ($id !== '') {
                hb_device_invite_revoke($id);
                $notice = 'invite-revoked';
            }
            break;

        case 'install-plugin':
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

    return $notice;
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

/**
 * タブの一覧。中身が出ないタブは並べない。
 *
 * @return array<string, array{0:string,1:callable}>
 */
function hb_admin_screen_tabs(): array
{
    $tabs = [
        'status'  => ['状態', 'hb_admin_screen_status'],
        'devices' => ['端末の許可', 'hb_admin_screen_devices'],
    ];

    if (current_user_can('install_plugins')) {
        $tabs['plugins'] = ['標準プラグイン', 'hb_admin_screen_plugins'];
    }

    $log = get_option(HB_GUARD_LOG_OPTION, []);

    if (is_array($log) && !empty($log)) {
        $tabs['log'] = ['遮断した要求', 'hb_admin_screen_guard_log'];
    }

    return $tabs;
}

/** いま開いているタブ。知らない値が来たら先頭に戻す。 */
function hb_admin_screen_current_tab(): string
{
    $tabs = hb_admin_screen_tabs();
    $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

    return isset($tabs[$tab]) ? $tab : (string) array_key_first($tabs);
}

function hb_admin_screen_render(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $tabs    = hb_admin_screen_tabs();
    $current = hb_admin_screen_current_tab();

    echo '<div class="wrap">';
    echo '<h1>HBベース</h1>';

    hb_admin_screen_notice();

    // WordPress標準のタブ。設定画面で複数の話題を分けるときの定型。
    echo '<h2 class="nav-tab-wrapper">';

    foreach ($tabs as $key => [$label, $callback]) {
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url(add_query_arg(['page' => 'hb-base', 'tab' => $key], admin_url('options-general.php'))),
            $key === $current ? ' nav-tab-active' : '',
            esc_html($label)
        );
    }

    echo '</h2>';

    echo '<form method="post">';
    wp_nonce_field('hb_base_action');

    // 操作のあと同じタブに戻れるように持たせる。
    printf('<input type="hidden" name="hb_tab" value="%s">', esc_attr($current));

    $tabs[$current][1]();

    echo '</form>';
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
    hb_admin_screen_row('入口の遮断', hb_hardening_summary());
    hb_admin_screen_row(
        '保守担当',
        hb_base_to_list(hb_base_const('HB_MAINTAINERS', ''))
            ? esc_html(implode(', ', hb_base_to_list(hb_base_const('HB_MAINTAINERS', ''))))
            : '<span class="description">未設定（全員クライアント扱い）</span>'
    );

    echo '</tbody></table>';
    echo '<p>設定値は <code>wp-config.php</code> の定数で変更します。この画面からは変更できません。</p>';
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

    $messages = hb_admin_screen_messages();

    if (isset($messages[$key])) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$key]));
    }
}

/** 通知のキーから本文を引く。AJAXでも使う。 */
function hb_admin_screen_message(string $key): string
{
    $messages = hb_admin_screen_messages();

    if ($key === 'plugin-failed') {
        $error = get_transient('hb_plugin_error_' . get_current_user_id());
        delete_transient('hb_plugin_error_' . get_current_user_id());

        return '導入に失敗しました。' . (is_string($error) ? $error : '');
    }

    return $messages[$key] ?? '';
}

function hb_admin_screen_messages(): array
{
    return [
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
    echo '<p>固定IPが無い回線からでも、ここで許可した端末だけは管理画面に入れます。</p>';

    hb_admin_screen_invite_box(get_transient('hb_last_invite_' . get_current_user_id()));

    $devices = hb_devices_all();

    echo '<h3>端末の一覧</h3>';

    if (empty($devices)) {
        echo '<p>許可・申請のある端末はまだありません。</p>';

        return;
    }

    uasort($devices, static function ($a, $b) {
        return ($b['last_seen'] ?? 0) <=> ($a['last_seen'] ?? 0);
    });

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">状態</th><th scope="col">端末</th><th scope="col">名前・理由</th>';
    echo '<th scope="col">IP</th><th scope="col">ブラウザ</th><th scope="col">日時</th><th scope="col">操作</th>';
    echo '</tr></thead><tbody>';

    foreach ($devices as $id => $device) {
        echo hb_admin_screen_device_row($id, $device);
    }

    echo '</tbody></table>';
}

/** 端末の1行。画面の描画とAJAXの差し替えで同じものを使う。 */
function hb_admin_screen_device_row(string $id, array $device): string
{
    $delete = ['delete' => ['label' => '削除', 'confirm' => 'この端末の記録を削除します。よろしいですか。']];

    $states = [
        'trusted' => ['有効', ['revoke'  => '無効にする'] + $delete],
        'revoked' => ['無効', ['approve' => '有効にする'] + $delete],
        'pending' => ['承認待ち', ['approve' => '有効にする'] + $delete],
    ];

    $state = (string) ($device['status'] ?? '');

    if (!isset($states[$state])) {
        return '';
    }

    [$label, $actions] = $states[$state];
    $when = (int) ($device['approved'] ?? $device['requested'] ?? $device['created'] ?? 0);

    $html  = sprintf('<tr data-hb-row="device-%s">', esc_attr($id));
    $html .= sprintf('<td>%s</td>', esc_html($label));
    $html .= sprintf('<td><code>%s</code></td>', esc_html(substr($id, 0, 8)));
    $html .= sprintf(
        '<td>%s%s</td>',
        esc_html((string) ($device['label'] ?? '')) ?: '—',
        ($device['note'] ?? '') !== ''
            ? '<br><span class="description">' . esc_html((string) $device['note']) . '</span>'
            : ''
    );
    $html .= sprintf('<td><code>%s</code></td>', esc_html((string) ($device['ip'] ?? '')));
    $html .= sprintf('<td><span class="description">%s</span></td>', esc_html(mb_substr((string) ($device['ua'] ?? ''), 0, 60)));
    $html .= sprintf('<td>%s</td>', $when ? esc_html(wp_date('Y-m-d H:i', $when)) : '—');

    ob_start();
    hb_admin_screen_row_actions($actions, 'hb_id', $id);
    $html .= '<td>' . ob_get_clean() . '</td></tr>';

    return $html;
}

function hb_admin_screen_invite_box($url): void
{
    echo '<h3>招待リンクを発行する</h3>';
    echo '<p>発行したURLを、許可したい端末で開いてもらいます。開いた端末だけが許可され、1回使うと無効になります。</p>';

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

    // 送信は画面全体を包むフォームに乗る。ボタン自身が操作名を送る。
    echo '<p class="submit"><button type="submit" name="hb_action" value="invite" class="button button-secondary">発行する</button></p>';

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

    echo '<h3>発行済みの招待リンク</h3>';
    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">状態</th><th scope="col">宛名</th><th scope="col">送り先</th>';
    echo '<th scope="col">発行</th><th scope="col">期限</th><th scope="col">使った端末</th><th scope="col">操作</th>';
    echo '</tr></thead><tbody>';

    foreach ($invites as $id => $invite) {
        echo hb_admin_screen_invite_row($id, $invite);
    }

    echo '</tbody></table>';
}

/** 招待リンクの1行。 */
function hb_admin_screen_invite_row(string $id, array $invite): string
{
    $states = [
        'open'    => '有効',
        'used'    => '使用済み',
        'revoked' => '取り消し済み',
        'expired' => '期限切れ',
    ];

    $state = hb_device_invite_state($invite);

    ob_start();

    echo sprintf('<tr data-hb-row="invite-%s">', esc_attr($id));
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

    return (string) ob_get_clean();
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

    foreach ($actions as $action => $spec) {
        $label   = is_array($spec) ? (string) $spec['label'] : (string) $spec;
        $confirm = is_array($spec) ? (string) ($spec['confirm'] ?? '') : '';

        // ボタン1つで「何を・どれに」を送る。
        // 行ごとに <form> を置くと、画面全体を包むフォームと入れ子になってしまう。
        printf(
            '<button type="submit" name="hb_row_action" value="%s" class="button" data-hb-ajax="1"%s>%s</button> ',
            esc_attr($action . '|' . $value),
            // 取り返しのつかない操作だけ一度止める。
            $confirm !== '' ? ' data-hb-confirm="' . esc_attr($confirm) . '"' : '',
            esc_html($label)
        );
    }
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

    echo '<p>未導入のものは裏で自動的に導入します。'
        . '入っているのに停止しているものは自動で有効化し、クライアントには「停止」リンクを出しません。</p>';

    echo '<table class="wp-list-table widefat striped"><thead><tr>';
    echo '<th scope="col">プラグイン</th><th scope="col">スラッグ</th><th scope="col">状態</th><th scope="col">操作</th>';
    echo '</tr></thead><tbody>';

    foreach ($status as $slug => $info) {
        echo hb_admin_screen_plugin_row($slug, $info);
    }

    echo '</tbody></table>';

    if (!empty($missing)) {
        printf(
            '<p class="submit"><button type="submit" name="hb_action" value="install-plugins" class="button button-secondary">%s</button></p>',
            esc_html(sprintf('足りない %d 本をまとめて導入', count($missing)))
        );
    }

    echo '<p>一覧は <code>HB_REQUIRED_PLUGINS</code> で差し替えられます。'
        . 'WordPress.org にあるものだけが対象です（ACF PRO などの有料プラグインは扱えません）。</p>';
}

/** 標準プラグインの1行。 */
function hb_admin_screen_plugin_row(string $slug, array $info): string
{
    $excluded = in_array($slug, hb_required_plugins_optout(), true);

    $labels = [
        'active'   => '有効',
        'inactive' => $excluded ? '停止中（自動有効化の対象外）' : '停止中',
        'missing'  => '未導入',
    ];

    if ($info['state'] === 'missing') {
        $actions = ['install-plugin' => 'いますぐ導入'];
    } elseif ($excluded) {
        $actions = ['restore-plugin' => '自動有効化に戻す'];
    } elseif ($info['state'] === 'inactive') {
        $actions = ['install-plugin' => '有効化'];
    } else {
        $actions = [];
    }

    ob_start();

    printf('<tr data-hb-row="plugin-%s">', esc_attr($slug));
    printf('<td>%s</td>', esc_html($info['name']));
    printf('<td><code>%s</code></td>', esc_html($slug));
    printf('<td>%s</td>', esc_html($labels[$info['state']]));
    echo '<td>';
    hb_admin_screen_row_actions($actions, 'hb_slug', $slug);
    echo '</td></tr>';

    return (string) ob_get_clean();
}

/* --- 遮断の記録 --------------------------------------------------------- */

function hb_admin_screen_guard_log(): void
{
    $log = get_option(HB_GUARD_LOG_OPTION, []);

    if (!is_array($log) || empty($log)) {
        return;
    }

    echo '<p>様子見モードでは、遮断したはずの要求がここに記録されます。'
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

    echo '<p class="submit"><button type="submit" name="hb_action" value="clear-log" class="button button-secondary">記録を消去</button></p>';
}
