<?php
/**
 * 管理画面のアクセス制限
 *
 * 守る範囲は /wp-admin/ と wp-login.php だけ。公開ページは一切触らない。
 *
 * 通す条件（どれか1つ満たせば通る）:
 *   - 接続元IPが HB_ADMIN_ALLOW_IPS に入っている
 *   - その端末が管理者に承認されている（招待URL／申請の承認／パスキーログイン）
 *   - 緊急解除キー付きで開いた
 *
 * 既定は off。HB_ADMIN_GUARD を 'log' → 'on' の順で上げるのが安全な導入手順。
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_GUARD_LOG_OPTION = 'hb_base_guard_log';
const HB_GUARD_LOG_MAX    = 50;

function hb_admin_guard_init(): void
{
    // 招待URLはガードが off でも効く。先に端末を通しておいてから on にできる。
    hb_guard_handle_invite();

    if (hb_admin_guard_mode() === 'off') {
        return;
    }

    hb_guard_check();
}

/* ---------------------------------------------------------------------------
 * 判定
 * -------------------------------------------------------------------------*/

function hb_guard_check(): void
{
    if (hb_guard_is_exempt()) {
        return;
    }

    if (!hb_guard_is_protected_request()) {
        return;
    }

    // 緊急解除。締め出された時の逃げ道。
    if (hb_guard_handle_unlock()) {
        return;
    }

    $deviceId = hb_device_current_id();

    if (hb_ip_in_list(hb_client_ip(), hb_admin_allow_ips())) {
        // 許可IPから入れた端末は、以後そのIPを離れても使えるように覚えておく。
        if ($deviceId !== '' && !hb_device_is_trusted($deviceId)) {
            hb_device_trust($deviceId, '', 'allowed-ip');
        }
        if ($deviceId !== '') {
            hb_device_touch($deviceId);
        }

        return;
    }

    if ($deviceId !== '' && hb_device_is_trusted($deviceId)) {
        hb_device_touch($deviceId);

        return;
    }

    hb_guard_log_block();

    if (hb_admin_guard_mode() === 'log') {
        return; // 様子見モードでは通す。
    }

    hb_guard_block();
}

/**
 * 守る対象のURLか。
 * 公開ページを巻き込まないよう、判定は保守的に（確実に管理面のものだけ）。
 */
function hb_guard_is_protected_request(): bool
{
    $path = hb_guard_request_path();

    $scope = strtolower((string) hb_base_const('HB_ADMIN_GUARD_SCOPE', 'admin+login'));

    if (hb_guard_path_is_login($path)) {
        return $scope !== 'admin';
    }

    return hb_guard_path_is_admin($path);
}

function hb_guard_request_path(): string
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = (string) parse_url($uri, PHP_URL_PATH);

    return $path === '' ? '/' : $path;
}

function hb_guard_path_is_admin(string $path): bool
{
    return strpos($path, '/wp-admin/') !== false || substr($path, -9) === '/wp-admin';
}

function hb_guard_path_is_login(string $path): bool
{
    if (basename($path) === 'wp-login.php') {
        return true;
    }

    // ログインURLを差し替えている案件では、そちらも守る対象にする。
    $slug = function_exists('hb_login_slug') ? hb_login_slug() : '';

    return $slug !== '' && trim($path, '/') === $slug;
}

/**
 * 守る対象から外すもの。
 *
 * admin-ajax.php と admin-post.php は公開側からも叩かれる入口なので外す。
 * ここを塞ぐとフロントのAJAXや問い合わせフォームが巻き添えで壊れる。
 * （この2つはログイン不要の処理しか受けない設計になっているので、
 *   管理面を守るという目的からは外して問題ない）
 */
function hb_guard_is_exempt(): bool
{
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }

    if (defined('DOING_CRON') && DOING_CRON) {
        return true;
    }

    if (defined('DOING_AJAX') && DOING_AJAX) {
        return true;
    }

    $file = basename(hb_guard_request_path());

    $exemptFiles = [
        'admin-ajax.php',
        'admin-post.php',
        'load-scripts.php',
        'load-styles.php',
    ];

    if (in_array($file, $exemptFiles, true)) {
        return true;
    }

    // 公開ページの「パスワード保護記事」はwp-login.phpにPOSTする。止めると閲覧者に影響が出る。
    $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

    if ($file === 'wp-login.php' && in_array($action, ['postpass', 'logout'], true)) {
        return true;
    }

    return (bool) apply_filters('hb_admin_guard_exempt', false);
}

/* ---------------------------------------------------------------------------
 * 通すための入口
 * -------------------------------------------------------------------------*/

/** 招待URL（/?hb-device-invite=...）を開いた端末を承認する。 */
function hb_guard_handle_invite(): void
{
    if (empty($_GET['hb-device-invite'])) {
        return;
    }

    $token  = sanitize_text_field(wp_unslash((string) $_GET['hb-device-invite']));
    $invite = hb_device_invite_consume($token);

    if ($invite === null) {
        hb_guard_render('この招待リンクは使用済みか、有効期限が切れています。管理者に再発行を依頼してください。', 410);
    }

    $id = hb_device_ensure_id();
    hb_device_trust($id, (string) ($invite['label'] ?? ''), 'invite');

    wp_safe_redirect(admin_url());
    exit;
}

/** 緊急解除キー（?hb-unlock=...）。 */
function hb_guard_handle_unlock(): bool
{
    $key = hb_admin_unlock_key();

    if ($key === '' || empty($_GET['hb-unlock'])) {
        return false;
    }

    $given = (string) wp_unslash($_GET['hb-unlock']);

    if (!hash_equals($key, $given)) {
        return false;
    }

    $id = hb_device_ensure_id();
    hb_device_trust($id, '緊急解除', 'unlock-key');

    // 解除キーがURL履歴やRefererに残らないよう、すぐ落とす。
    wp_safe_redirect(remove_query_arg('hb-unlock'));
    exit;
}

/* ---------------------------------------------------------------------------
 * 遮断
 * -------------------------------------------------------------------------*/

function hb_guard_block(): void
{
    $id = hb_device_ensure_id();

    // 遮断画面から出された申請を受ける。
    if (!empty($_POST['hb_device_request'])) {
        hb_guard_handle_request($id);
    }

    hb_guard_render_blocked($id);
}

function hb_guard_handle_request(string $id): void
{
    $token = isset($_POST['hb_token']) ? (string) wp_unslash($_POST['hb_token']) : '';

    if (!hash_equals(hb_guard_form_token($id), $token)) {
        hb_guard_render_blocked($id, 'フォームの有効期限が切れました。もう一度お試しください。');
    }

    // 連投防止。1端末1時間に1回で十分。
    if (get_transient('hb_req_' . $id)) {
        hb_guard_render_blocked($id, '申請はすでに送信済みです。管理者の承認をお待ちください。');
    }

    $name = sanitize_text_field(wp_unslash((string) ($_POST['hb_name'] ?? '')));
    $note = sanitize_textarea_field(wp_unslash((string) ($_POST['hb_note'] ?? '')));

    hb_device_request($id, mb_substr($name, 0, 60), mb_substr($note, 0, 300));
    set_transient('hb_req_' . $id, 1, HOUR_IN_SECONDS);

    hb_guard_notify_admin($id, $name, $note);

    hb_guard_render_blocked($id, '申請を送信しました。管理者が承認すると、この端末からアクセスできるようになります。', true);
}

/** 申請が来たことを管理者メールへ知らせる。送信に失敗しても遮断画面は出す。 */
function hb_guard_notify_admin(string $id, string $name, string $note): void
{
    $to = (string) get_option('admin_email');

    if ($to === '') {
        return;
    }

    $subject = sprintf('[%s] 管理画面へのアクセス申請', wp_specialchars_decode((string) get_option('blogname')));

    $body = implode("\n", [
        '管理画面へのアクセス申請がありました。',
        '',
        '名前   : ' . ($name !== '' ? $name : '(未記入)'),
        '理由   : ' . ($note !== '' ? $note : '(未記入)'),
        '端末   : ' . substr($id, 0, 8),
        'IP     : ' . hb_client_ip(),
        'ブラウザ: ' . hb_device_ua(),
        '',
        '承認はこちら: ' . admin_url('options-general.php?page=hb-base'),
    ]);

    wp_mail($to, $subject, $body);
}

/** 遮断画面のフォーム用トークン。WPのnonceはこの時点では使えないので自前で作る。 */
function hb_guard_form_token(string $id): string
{
    // 日付を混ぜて実質1日で失効させる。
    return hash_hmac('sha256', 'hb-guard-form|' . $id . '|' . gmdate('Y-m-d'), wp_salt('auth'));
}

function hb_guard_render_blocked(string $id, string $message = '', bool $submitted = false): void
{
    $context = [
        'id'        => $id,
        'ip'        => hb_client_ip(),
        'message'   => $message,
        'submitted' => $submitted,
        'token'     => hb_guard_form_token($id),
        'site'      => (string) get_option('blogname'),
        'can_request' => (bool) hb_base_const('HB_ADMIN_GUARD_ALLOW_REQUEST', true),
    ];

    status_header(403);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Content-Type: text/html; charset=utf-8', true);

    include HB_BASE_DIR . '/views/blocked.php';
    exit;
}

/** 招待リンク切れなど、単純なメッセージだけ返したいとき。 */
function hb_guard_render(string $message, int $status = 403): void
{
    status_header($status);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);

    wp_die(esc_html($message), 'アクセスできません', ['response' => $status]);
}

/* ---------------------------------------------------------------------------
 * 記録
 * -------------------------------------------------------------------------*/

/** 遮断した（または log モードで遮断したはずの）要求を残す。導入時の確認用。 */
function hb_guard_log_block(): void
{
    $log = get_option(HB_GUARD_LOG_OPTION, []);

    if (!is_array($log)) {
        $log = [];
    }

    array_unshift($log, [
        'time'   => time(),
        'ip'     => hb_client_ip(),
        'path'   => hb_guard_request_path(),
        'device' => substr(hb_device_current_id(), 0, 8),
        'ua'     => mb_substr(hb_device_ua(), 0, 120),
        'mode'   => hb_admin_guard_mode(),
    ]);

    update_option(HB_GUARD_LOG_OPTION, array_slice($log, 0, HB_GUARD_LOG_MAX), false);
}
