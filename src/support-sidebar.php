<?php
/**
 * サポートの固定パネル
 *
 * 管理画面のどの画面からでも、右側の常設パネルで質問できるようにする。
 * サポート画面まで行かないと聞けない＝聞かれない、になるため。
 *
 * 画面の組み立てはPHPが返し、JSは差し替えと送信だけにする。
 * 状態からラベルを組み立てる判断をJS側に置くと、同じ判断がPHPとJSに分かれて必ずずれる。
 *
 * 止めるとき:
 *   define('HB_SUPPORT_SIDEBAR', false);
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_support_sidebar_init(): void
{
    if (!hb_base_const('HB_SUPPORT_SIDEBAR', true)) {
        return;
    }

    add_action('admin_enqueue_scripts', 'hb_support_sidebar_assets');
    add_action('admin_footer', 'hb_support_sidebar_markup');
    add_action('wp_ajax_hb_support_sidebar', 'hb_support_sidebar_ajax');
}

/** 出してよい相手か。 */
function hb_support_sidebar_visible(): bool
{
    return current_user_can('edit_posts') && !empty(hb_support_features()['tickets']);
}

function hb_support_sidebar_assets(): void
{
    if (!hb_support_sidebar_visible()) {
        return;
    }

    wp_enqueue_style(
        'hb-support-sidebar',
        plugins_url('assets/support-sidebar.css', HB_BASE_FILE),
        [],
        HB_BASE_VERSION
    );

    wp_enqueue_script(
        'hb-support-sidebar',
        plugins_url('assets/support-sidebar.js', HB_BASE_FILE),
        [],
        HB_BASE_VERSION,
        true
    );

    wp_localize_script('hb-support-sidebar', 'hbSupportSidebar', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('hb_support_sidebar'),
    ]);
}

/** 器だけ出す。中身は開いた時に取りに行く（全画面で毎回APIを叩かない）。 */
function hb_support_sidebar_markup(): void
{
    if (!hb_support_sidebar_visible()) {
        return;
    }

    $unread = (int) (hb_support_unread()['unread_total'] ?? 0);
    ?>
    <button type="button" id="hb-support-toggle" aria-expanded="false" aria-controls="hb-support-panel">
        <span class="dashicons dashicons-sos" aria-hidden="true"></span>
        サポート
        <span class="hb-count"<?php echo $unread > 0 ? '' : ' style="display:none"'; ?>><?php echo (int) $unread; ?></span>
    </button>

    <div id="hb-support-panel" role="dialog" aria-label="サポート">
        <div class="hb-head">
            <strong>サポート</strong>
            <button type="button" class="button-link" data-hb-close aria-label="閉じる">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>
        <div class="hb-body"></div>
        <div class="hb-foot"></div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * パネルの中身
 * -------------------------------------------------------------------------*/

function hb_support_sidebar_ajax(): void
{
    check_ajax_referer('hb_support_sidebar', 'nonce');

    if (!hb_support_sidebar_visible()) {
        wp_send_json_error();
    }

    $op     = sanitize_key((string) wp_unslash($_POST['op'] ?? 'list'));
    $ticket = sanitize_text_field((string) wp_unslash($_POST['ticket'] ?? ''));

    if ($op === 'reply' && $ticket !== '') {
        $body = sanitize_textarea_field((string) wp_unslash($_POST['body'] ?? ''));

        if ($body !== '') {
            $sent = hb_support_reply($ticket, $body);

            if (is_wp_error($sent)) {
                wp_send_json_success(hb_support_sidebar_thread($ticket, hb_support_message($sent)));
            }
        }

        $op = 'thread';
    }

    if ($op === 'create') {
        $title = sanitize_text_field((string) wp_unslash($_POST['title'] ?? ''));
        $body  = sanitize_textarea_field((string) wp_unslash($_POST['body'] ?? ''));
        $kind  = sanitize_key((string) wp_unslash($_POST['kind'] ?? 'update'));

        if ($title === '' || $body === '') {
            wp_send_json_success(hb_support_sidebar_new('件名と内容を入力してください。'));
        }

        $created = hb_support_create($title, $body, $kind);

        if (is_wp_error($created)) {
            wp_send_json_success(hb_support_sidebar_new(hb_support_message($created)));
        }

        wp_send_json_success(hb_support_sidebar_thread((string) ($created['id'] ?? '')));
    }

    if ($op === 'new') {
        wp_send_json_success(hb_support_sidebar_new());
    }

    if ($op === 'thread' && $ticket !== '') {
        wp_send_json_success(hb_support_sidebar_thread($ticket));
    }

    wp_send_json_success(hb_support_sidebar_list());
}

/**
 * 依頼の一覧。
 *
 * @return array<string,mixed>
 */
function hb_support_sidebar_list(): array
{
    $data = hb_support_tickets(['state' => 'open']);

    if (is_wp_error($data)) {
        return [
            'body' => '<p>' . esc_html(hb_support_message($data)) . '</p>',
            'foot' => '',
        ];
    }

    $items = is_array($data['items'] ?? null) ? $data['items'] : [];

    ob_start();

    if (empty($items)) {
        echo '<p>いま対応中の依頼はありません。</p>';
    }

    foreach ($items as $item) {
        $unread = (int) ($item['unread_count'] ?? 0);

        printf(
            '<a href="#" class="hb-item" data-hb-open="%s"><strong>%s</strong>%s<div class="hb-meta">%s ・ %s</div></a>',
            esc_attr((string) $item['id']),
            esc_html((string) ($item['title'] ?? '')),
            $unread > 0 ? ' <span class="hb-count">' . $unread . '</span>' : '',
            esc_html((string) ($item['status_label'] ?? '')),
            esc_html(hb_support_time($item['last_message_at'] ?? null))
        );
    }

    $body = (string) ob_get_clean();

    // 「新しく依頼する」もフォームとして出す。JS側の分岐を増やさないため
    // （送信の経路が1本なら、増やすたびにJSを触らずに済む）。
    $foot = !empty(hb_support_features()['create'])
        ? '<form data-hb-form="new"><button type="submit" class="button button-primary">新しく依頼する</button></form>'
        : '';

    return [
        'body'   => $body,
        'foot'   => $foot,
        'unread' => (int) ($data['unread_total'] ?? 0),
    ];
}

/**
 * やり取り。
 *
 * @return array<string,mixed>
 */
function hb_support_sidebar_thread(string $id, string $error = ''): array
{
    $data = hb_support_ticket($id);

    if (is_wp_error($data)) {
        return [
            'body' => '<p>' . esc_html(hb_support_message($data)) . '</p>'
                . '<p><a href="#" data-hb-back>&larr; 一覧へ</a></p>',
            'foot' => '',
        ];
    }

    // 未読があれば既読にする。read は冪等だが、無駄な往復はしない。
    if ((int) ($data['unread_count'] ?? 0) > 0) {
        hb_support_read($id);
    }

    $messages = is_array($data['messages']['items'] ?? null) ? $data['messages']['items'] : [];

    ob_start();

    echo '<p><a href="#" data-hb-back>&larr; 一覧へ</a></p>';

    printf(
        '<p><strong>%s</strong><br><span class="description">%s</span></p>',
        esc_html((string) ($data['title'] ?? '')),
        esc_html((string) ($data['status_label'] ?? ''))
    );

    if ($error !== '') {
        printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html($error));
    }

    foreach ($messages as $message) {
        $author  = is_array($message['author'] ?? null) ? $message['author'] : [];
        $isStaff = ($author['type'] ?? '') === 'staff';

        printf(
            '<div class="hb-msg%s"><div class="hb-who">%s ・ %s</div><div class="hb-text">%s</div></div>',
            $isStaff ? ' is-staff' : '',
            esc_html((string) ($author['name'] ?? '')),
            esc_html(hb_support_time($message['at'] ?? null)),
            hb_support_text((string) ($message['body'] ?? ''))
        );
    }

    $body = (string) ob_get_clean();

    ob_start();
    ?>
    <form data-hb-form="reply">
        <input type="hidden" name="ticket" value="<?php echo esc_attr($id); ?>">
        <textarea name="body" rows="3" maxlength="10000" placeholder="ご用件をご記入ください。"></textarea>
        <p><button type="submit" class="button button-primary">送信</button></p>
    </form>
    <?php
    $foot = (string) ob_get_clean();

    return [
        'body'          => $body,
        'foot'          => $foot,
        'scroll_bottom' => true,
        'unread'        => (int) (hb_support_unread()['unread_total'] ?? 0),
    ];
}

/**
 * 新規の依頼。
 *
 * @return array<string,mixed>
 */
function hb_support_sidebar_new(string $error = ''): array
{
    ob_start();

    echo '<p><a href="#" data-hb-back>&larr; 一覧へ</a></p>';

    if ($error !== '') {
        printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html($error));
    }

    echo '<p>ご用件をお知らせください。担当者から折り返しご連絡します。</p>';

    $body = (string) ob_get_clean();

    ob_start();
    ?>
    <form data-hb-form="create">
        <p><input type="text" name="title" class="widefat" maxlength="120" placeholder="件名" required></p>
        <p>
            <select name="kind" class="widefat">
                <?php foreach (hb_support_kinds() as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p><textarea name="body" rows="4" maxlength="10000" placeholder="内容" required></textarea></p>
        <p><button type="submit" class="button button-primary">依頼する</button></p>
    </form>
    <?php
    $foot = (string) ob_get_clean();

    return ['body' => $body, 'foot' => $foot];
}
