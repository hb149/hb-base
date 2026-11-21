<?php
/**
 * 保守サポートの管理画面
 *
 * 一覧 → 詳細（やり取り＋返信）の2画面だけ。
 * 表示は views/support/ に置き、ここは取得と分岐に徹する。
 *
 * 本文はアプリ側が平文だけを受ける約束なので、出すときは esc_html() + nl2br()。
 * HTMLを通さないので、XSSの責任がこちら1箇所で済む。
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_support_screen_init(): void
{
    if (!hb_support_configured()) {
        return;
    }

    add_action('admin_menu', 'hb_support_screen_menu');
    add_action('admin_init', 'hb_support_screen_handle');
    add_action('admin_bar_menu', 'hb_support_screen_admin_bar', 80);
}

/* ---------------------------------------------------------------------------
 * メニュー
 * -------------------------------------------------------------------------*/

function hb_support_screen_menu(): void
{
    $features = hb_support_features();

    // サイトの保守サポートが終わっている／停止しているときは出さない。
    if (empty($features['tickets'])) {
        return;
    }

    add_menu_page(
        'サポート',
        'サポート' . hb_support_screen_badge(),
        'edit_posts',
        'hb-support',
        'hb_support_screen_render',
        'dashicons-sos',
        3
    );
}

/** 未読の数。巡回で取れた最後の値を読むだけで、毎回APIは叩かない。 */
function hb_support_screen_badge(): string
{
    $unread = (int) (hb_support_unread()['unread_total'] ?? 0);

    if ($unread < 1) {
        return '';
    }

    return sprintf(
        ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
        $unread
    );
}

/** @param WP_Admin_Bar $bar */
function hb_support_screen_admin_bar($bar): void
{
    if (!current_user_can('edit_posts') || empty(hb_support_features()['tickets'])) {
        return;
    }

    $counts = hb_support_unread();
    $unread = (int) ($counts['unread_total'] ?? 0);
    $action = (int) ($counts['action_required'] ?? 0);

    if ($unread < 1 && $action < 1) {
        return;
    }

    $label = $unread > 0 ? sprintf('サポート %d件', $unread) : sprintf('要対応 %d件', $action);

    $bar->add_node([
        'id'    => 'hb-support',
        'title' => $label,
        'href'  => admin_url('admin.php?page=hb-support'),
    ]);
}

/* ---------------------------------------------------------------------------
 * 操作
 * -------------------------------------------------------------------------*/

function hb_support_screen_handle(): void
{
    if (!isset($_POST['hb_support_action']) || !current_user_can('edit_posts')) {
        return;
    }

    check_admin_referer('hb_support');

    $action = sanitize_key(wp_unslash((string) $_POST['hb_support_action']));
    $id     = sanitize_text_field(wp_unslash((string) ($_POST['hb_ticket'] ?? '')));
    $notice = '';

    if ($action === 'reply') {
        // 本文は平文。改行だけ残して、タグは入れさせない。
        $body = sanitize_textarea_field(wp_unslash((string) ($_POST['hb_body'] ?? '')));

        $result = $body === ''
            ? new WP_Error('invalid_request', '本文を入力してください。', ['status' => 400])
            : hb_support_reply($id, $body);

        $notice = is_wp_error($result) ? hb_support_message($result) : 'sent';
    } elseif (in_array($action, ['approve', 'accept', 'withdraw'], true)) {
        $comment = sanitize_textarea_field(wp_unslash((string) ($_POST['hb_comment'] ?? '')));
        $result  = hb_support_action($id, $action, $comment);

        $notice = is_wp_error($result) ? hb_support_message($result) : 'done';
    }

    if ($notice !== '') {
        set_transient('hb_support_notice_' . get_current_user_id(), $notice, 60);
    }

    wp_safe_redirect(add_query_arg(
        array_filter(['page' => 'hb-support', 'ticket' => $id]),
        admin_url('admin.php')
    ));
    exit;
}

/* ---------------------------------------------------------------------------
 * 表示
 * -------------------------------------------------------------------------*/

function hb_support_screen_render(): void
{
    if (!current_user_can('edit_posts')) {
        return;
    }

    $ticket_id = isset($_GET['ticket']) ? sanitize_text_field(wp_unslash($_GET['ticket'])) : '';

    echo '<div class="wrap">';

    hb_support_screen_notice();

    if ($ticket_id !== '') {
        hb_support_screen_detail($ticket_id);
    } else {
        hb_support_screen_list();
    }

    echo '</div>';
}

function hb_support_screen_notice(): void
{
    $key    = 'hb_support_notice_' . get_current_user_id();
    $notice = get_transient($key);

    if (!is_string($notice) || $notice === '') {
        return;
    }

    delete_transient($key);

    $messages = [
        'sent' => ['success', '送信しました。'],
        'done' => ['success', '反映しました。'],
    ];

    [$type, $text] = $messages[$notice] ?? ['error', $notice];

    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        esc_attr($type),
        esc_html($text)
    );
}

function hb_support_screen_list(): void
{
    $state = isset($_GET['state']) ? sanitize_key(wp_unslash($_GET['state'])) : 'open';
    $state = in_array($state, ['open', 'closed', 'all'], true) ? $state : 'open';

    $data = hb_support_tickets(['state' => $state]);

    echo '<h1>サポート</h1>';

    if (is_wp_error($data)) {
        hb_support_screen_error($data);

        return;
    }

    include HB_BASE_DIR . '/views/support/list.php';
}

function hb_support_screen_detail(string $id): void
{
    $data = hb_support_ticket($id);

    if (is_wp_error($data)) {
        // 無いチケットは一覧へ戻す。開いたままにしても何もできない。
        if ($data->get_error_code() === 'not_found') {
            printf(
                '<h1>サポート</h1><div class="notice notice-error"><p>%s</p></div><p><a href="%s">一覧へ戻る</a></p>',
                esc_html(hb_support_message($data)),
                esc_url(admin_url('admin.php?page=hb-support'))
            );

            return;
        }

        echo '<h1>サポート</h1>';
        hb_support_screen_error($data);

        return;
    }

    $features = hb_support_features();

    include HB_BASE_DIR . '/views/support/detail.php';
}

/**
 * つながらないときの出し方。
 *
 * 空白の画面にしない。最後に取れた未読だけでも出して、
 * 「動いていないのか、繋がっていないだけなのか」が分かるようにする。
 *
 * @param WP_Error $error
 */
function hb_support_screen_error($error): void
{
    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html(hb_support_message($error))
    );

    $counts = hb_support_unread();

    if (empty($counts)) {
        return;
    }

    printf(
        '<p class="description">最後に取れた時点では、未読 %d件 / 対応中 %d件 でした。</p>',
        (int) ($counts['unread_total'] ?? 0),
        (int) ($counts['open_total'] ?? 0)
    );
}

/* ---------------------------------------------------------------------------
 * 表示の小物
 * -------------------------------------------------------------------------*/

/** 本文を出す。平文しか来ない約束なので、エスケープしてから改行だけ活かす。 */
function hb_support_text(string $body): string
{
    return nl2br(esc_html($body), false);
}

/** ISO8601 を現地時刻にする。 */
function hb_support_time(?string $iso): string
{
    if (!$iso) {
        return '—';
    }

    $ts = strtotime($iso);

    return $ts ? wp_date('n/j H:i', $ts) : '—';
}

/** 客ができる操作のラベル。 */
function hb_support_action_label(string $action): string
{
    $labels = [
        'approve'  => 'この内容で承認する',
        'accept'   => '確認しました（完了にする）',
        'withdraw' => '取り下げる',
    ];

    return $labels[$action] ?? $action;
}
