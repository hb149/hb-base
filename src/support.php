<?php
/**
 * 保守サポート（社内Appのチケットの窓口）
 *
 * ■ 置いているもの
 *   WordPress 側はチケットの実体を持たない。社内Appの /api/wp/* を叩いて出すだけ。
 *   サイトが増えても情報が分散しないようにするため。
 *
 * ■ 仕様の正本はアプリ側
 *   ~/作業場/App/hb-documents/app/docs/wp-support-api-design.md
 *   応答の形の正本は docs/hb-support/fixtures/（このリポジトリにコピーがある）
 *   穴に気づいたら実装で埋めず、アプリ側の qa.md に書く。
 *
 * ■ 設定（3つ。未設定ならこのモジュールは何もしない）
 *   define('HB_SUPPORT_URL',    'https://app.example.com'); // 末尾スラッシュ無し
 *   define('HB_SUPPORT_TOKEN',  'wps_...');                 // サイトごとに違う
 *   define('HB_SUPPORT_SECRET', 'whsec_...');               // Webhook検証用（使うまで空でよい）
 *
 * ■ アプリがまだ無いとき
 *   define('HB_SUPPORT_MOCK', true);
 *   docs/hb-support/fixtures/ の内容を応答として返す。画面を先に作るため。
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_SUPPORT_FEATURES_OPTION = 'hb_support_features';
const HB_SUPPORT_UNREAD_OPTION   = 'hb_support_unread';
const HB_SUPPORT_CRON_HOOK       = 'hb_support_poll_unread';

function hb_support_init(): void
{
    if (!hb_support_configured()) {
        return;
    }

    add_action(HB_SUPPORT_CRON_HOOK, 'hb_support_poll_unread');
    add_action('rest_api_init', 'hb_support_register_webhook');

    if (is_admin()) {
        add_action('admin_init', 'hb_support_schedule_poll');
        add_action('admin_init', 'hb_support_maybe_refresh_features', 20);
    }
}

/** features は1日1回だけ取り直す（毎回 ping を叩かない）。 */
function hb_support_maybe_refresh_features(): void
{
    if (!current_user_can('edit_posts')) {
        return;
    }

    $saved = get_option(HB_SUPPORT_FEATURES_OPTION);
    $at    = is_array($saved) ? (int) ($saved['at'] ?? 0) : 0;

    if ((time() - $at) < DAY_IN_SECONDS) {
        return;
    }

    hb_support_refresh_features();
}

/** 設定が揃っているか。URLとトークンが無ければ何もしない。 */
function hb_support_configured(): bool
{
    if (hb_support_mock()) {
        return true;
    }

    return hb_support_url() !== '' && (string) hb_base_const('HB_SUPPORT_TOKEN', '') !== '';
}

function hb_support_url(): string
{
    return untrailingslashit((string) hb_base_const('HB_SUPPORT_URL', ''));
}

/** アプリ側の実装待ちのあいだ、フィクスチャを応答として使う。 */
function hb_support_mock(): bool
{
    return (bool) hb_base_const('HB_SUPPORT_MOCK', false);
}

/* ---------------------------------------------------------------------------
 * 通信
 * -------------------------------------------------------------------------*/

/**
 * APIを叩く。
 *
 * 失敗は WP_Error で返し、error_code にアプリ側の code（invalid_token など）、
 * error_data に ['status' => HTTP, 'body' => 応答] を入れる。
 * 呼び出し側は code で分岐する。
 *
 * @param array<string,mixed> $query
 * @param array<string,mixed>|null $body
 * @return array<string,mixed>|WP_Error
 */
function hb_support_request(string $method, string $path, array $query = [], ?array $body = null, bool $slow = false)
{
    if (hb_support_mock()) {
        return hb_support_mock_response($method, $path);
    }

    $url = hb_support_url() . $path;

    if (!empty($query)) {
        $url = add_query_arg(array_filter($query, static function ($v) { return $v !== null && $v !== ''; }), $url);
    }

    $args = [
        // 接続と読み取りは分けない（wp_remote_* に分ける口が無く、transport で挙動が変わるため）。
        // 人が待っている画面は10秒、裏の巡回は長くてよいので15秒。
        'timeout'   => $slow ? 15 : 10,
        'sslverify' => true,
        'method'    => $method,
        'headers'   => [
            'Authorization' => 'Bearer ' . (string) hb_base_const('HB_SUPPORT_TOKEN', ''),
            'Accept'        => 'application/json',
        ],
    ];

    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        $args['body']                    = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        // 通信そのものが失敗。アプリ側のcodeは無いので、こちらで付ける。
        return new WP_Error('unreachable', $response->get_error_message(), ['status' => 0]);
    }

    return hb_support_parse($response);
}

/**
 * @param array|WP_Error $response
 * @return array<string,mixed>|WP_Error
 */
function hb_support_parse($response)
{
    $status = (int) wp_remote_retrieve_response_code($response);
    $raw    = (string) wp_remote_retrieve_body($response);

    // 未読の巡回は変化が無ければ 304（本文0バイト）。
    if ($status === 304) {
        return ['_not_modified' => true];
    }

    $data = json_decode($raw, true);

    if ($status >= 200 && $status < 300) {
        return is_array($data) ? $data : [];
    }

    // エラーの形は { code, error }（409 だけ allowed_actions を足す）。
    $code    = is_array($data) && isset($data['code']) ? (string) $data['code'] : 'server_error';
    $message = is_array($data) && isset($data['error']) ? (string) $data['error'] : '';

    return new WP_Error($code, $message, [
        'status' => $status,
        'body'   => is_array($data) ? $data : [],
    ]);
}

/**
 * 画面に出す文面。
 *
 * 401 / 403 / 404 / 409 以外は、理由を見せずに「いま接続できません」だけにする。
 * アプリ側の不具合やデプロイ中の内容を客に見せても何もできない。
 *
 * @param WP_Error $error
 */
function hb_support_message($error): string
{
    $shown = ['invalid_token', 'plugin_disabled', 'site_inactive', 'not_found', 'conflict', 'invalid_request', 'too_large'];

    if (in_array($error->get_error_code(), $shown, true) && $error->get_error_message() !== '') {
        return $error->get_error_message();
    }

    return 'いま接続できません。しばらくしてからお試しください。';
}

/* ---------------------------------------------------------------------------
 * 各エンドポイント
 * -------------------------------------------------------------------------*/

/** @return array<string,mixed>|WP_Error */
function hb_support_ping()
{
    return hb_support_request('GET', '/api/wp/ping');
}

/** @return array<string,mixed>|WP_Error */
function hb_support_tickets(array $query = [])
{
    return hb_support_request('GET', '/api/wp/tickets', $query);
}

/** @return array<string,mixed>|WP_Error */
function hb_support_ticket(string $id, array $query = [])
{
    return hb_support_request('GET', '/api/wp/tickets/' . rawurlencode($id), $query);
}

/** @return array<string,mixed>|WP_Error */
function hb_support_reply(string $id, string $body)
{
    return hb_support_request(
        'POST',
        '/api/wp/tickets/' . rawurlencode($id) . '/messages',
        [],
        ['body' => $body, 'attachment_ids' => []]
    );
}

/**
 * 既読にする。
 *
 * 詳細の GET は既読にしない約束なので、この口を明示的に叩く。
 * 応答の unread_total は**サイト全体の残り**なので、バッジをそのまま書き換えられる。
 *
 * @return array<string,mixed>|WP_Error
 */
function hb_support_read(string $id)
{
    $result = hb_support_request('POST', '/api/wp/tickets/' . rawurlencode($id) . '/read', [], []);

    if (!is_wp_error($result) && isset($result['unread_total'])) {
        $counts = hb_support_unread();
        $counts['unread_total'] = (int) $result['unread_total'];

        update_option(HB_SUPPORT_UNREAD_OPTION, ['at' => time(), 'counts' => $counts], false);
    }

    return $result;
}

/**
 * 新規に起票する。
 *
 * 送れるのは4つだけ。severity と status はサーバーが決める
 * （客に選ばせると全部「重大」になる、という理由でアプリ側が固定している）。
 *
 * @return array<string,mixed>|WP_Error 201＝詳細と同じ形
 */
function hb_support_create(string $title, string $body, string $kind)
{
    $kinds = ['update', 'incident', 'consult'];

    return hb_support_request('POST', '/api/wp/tickets', [], [
        'title'          => $title,
        'body'           => $body,
        'kind'           => in_array($kind, $kinds, true) ? $kind : 'update',
        'attachment_ids' => [],
    ]);
}

/** WP から選ばせる種別。spot・managed・growth は社内が付け替えるので出さない。 */
function hb_support_kinds(): array
{
    return [
        'update'   => '更新作業',
        'incident' => '障害・不具合',
        'consult'  => '相談',
    ];
}

/** @return array<string,mixed>|WP_Error */
function hb_support_action(string $id, string $action, string $comment = '')
{
    $payload = ['action' => $action];

    if ($comment !== '') {
        $payload['comment'] = $comment;
    }

    return hb_support_request('POST', '/api/wp/tickets/' . rawurlencode($id) . '/actions', [], $payload);
}

/* ---------------------------------------------------------------------------
 * 機能の出し分け（ping の features）
 * -------------------------------------------------------------------------*/

/**
 * 出していい機能。
 *
 * ping が取れた時だけ更新する。取れない時は前回の値、無ければ全部 true。
 * 通信が落ちただけでメニューが消えると、客には「機能が無くなった」と見える。
 *
 * @return array<string,bool>
 */
function hb_support_features(): array
{
    $saved = get_option(HB_SUPPORT_FEATURES_OPTION);

    if (is_array($saved) && isset($saved['features']) && is_array($saved['features'])) {
        return hb_support_normalize_features($saved['features']);
    }

    return hb_support_normalize_features(null);
}

/**
 * 欠けているキーは false 扱い。ただし一度も取れていないときは全部 true。
 *
 * @param array<string,mixed>|null $features
 * @return array<string,bool>
 */
function hb_support_normalize_features(?array $features): array
{
    $keys = ['tickets', 'create', 'actions', 'attachments', 'webhook'];

    if ($features === null) {
        return array_fill_keys($keys, true);
    }

    $out = [];

    foreach ($keys as $key) {
        $out[$key] = !empty($features[$key]);
    }

    return $out;
}

/**
 * ping を取り直して features を覚える。設定画面の「接続テスト」と1日1回。
 *
 * @return array<string,mixed>|WP_Error ping の応答
 */
function hb_support_refresh_features()
{
    $ping = hb_support_ping();

    if (is_wp_error($ping)) {
        return $ping;
    }

    update_option(HB_SUPPORT_FEATURES_OPTION, [
        'at'       => time(),
        'features' => is_array($ping['features'] ?? null) ? $ping['features'] : [],
        'site'     => is_array($ping['site'] ?? null) ? $ping['site'] : [],
        'env'      => (string) ($ping['env'] ?? ''),
        'version'  => (int) ($ping['api_version'] ?? 0),
    ], false);

    return $ping;
}

/* ---------------------------------------------------------------------------
 * 未読の巡回
 * -------------------------------------------------------------------------*/

function hb_support_schedule_poll(): void
{
    if (!current_user_can('read') || wp_next_scheduled(HB_SUPPORT_CRON_HOOK)) {
        return;
    }

    wp_schedule_event(time() + 60, 'hb_support_5min', HB_SUPPORT_CRON_HOOK);
}

/** 5分間隔を足す。WordPress の既定にはこの間隔が無い。 */
add_filter('cron_schedules', 'hb_support_cron_schedule');

function hb_support_cron_schedule(array $schedules): array
{
    $schedules['hb_support_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => '5分ごと（HBサポート）'];

    return $schedules;
}

/**
 * 未読を取り直す。ETag が一致すれば 304 が返るので、そのときは何もしない。
 */
function hb_support_poll_unread(): void
{
    $unread = hb_support_request('GET', '/api/wp/unread', [], null, true);

    if (is_wp_error($unread) || !empty($unread['_not_modified'])) {
        return;
    }

    update_option(HB_SUPPORT_UNREAD_OPTION, [
        'at'     => time(),
        'counts' => $unread,
    ], false);
}

/**
 * 最後に取れた未読。画面はこれを読む（毎回APIを叩かない）。
 *
 * @return array<string,mixed>
 */
function hb_support_unread(): array
{
    $saved = get_option(HB_SUPPORT_UNREAD_OPTION);

    return is_array($saved) && is_array($saved['counts'] ?? null) ? $saved['counts'] : [];
}

/* ---------------------------------------------------------------------------
 * Webhook の受け口
 * -------------------------------------------------------------------------*/

function hb_support_register_webhook(): void
{
    register_rest_route('hb-base/v1', '/hook', [
        'methods'  => 'POST',
        'callback' => 'hb_support_handle_webhook',
        // 署名の検証そのものを権限判定にする。__return_true にしない。
        'permission_callback' => 'hb_support_verify_signature',
    ]);
}

/**
 * 署名を検証する。
 *
 * HMAC-SHA256(secret, "<timestamp>.<生のボディ>") を hex で比較し、
 * 時刻が5分以上ずれていたら捨てる（同じ物を後から投げ直されるのを防ぐ）。
 */
function hb_support_verify_signature(WP_REST_Request $request): bool
{
    $secret = (string) hb_base_const('HB_SUPPORT_SECRET', '');

    if ($secret === '') {
        return false;
    }

    $timestamp = (string) $request->get_header('x-hb-timestamp');
    $signature = (string) $request->get_header('x-hb-signature');

    if ($timestamp === '' || $signature === '') {
        return false;
    }

    if (abs(time() - (int) $timestamp) > 5 * MINUTE_IN_SECONDS) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $request->get_body(), $secret);

    return hash_equals($expected, $signature);
}

/**
 * 受けたら未読を取り直すだけ。
 * 中身は薄い（idと種類だけ）ので、本文は改めて取りに行く。
 */
function hb_support_handle_webhook(WP_REST_Request $request): WP_REST_Response
{
    hb_support_poll_unread();

    return new WP_REST_Response(['ok' => true], 200);
}

/* ---------------------------------------------------------------------------
 * モック（アプリ側の実装待ち）
 * -------------------------------------------------------------------------*/

/**
 * フィクスチャを応答として返す。
 *
 * 契約の正本そのものを食わせるので、画面がフィクスチャで描けることが
 * そのまま「形に追従できている」証明になる。
 *
 * @return array<string,mixed>|WP_Error
 */
function hb_support_mock_response(string $method, string $path)
{
    $file = 'tickets-list';

    if (strpos($path, '/api/wp/ping') === 0) {
        $file = 'ping';
    } elseif (strpos($path, '/api/wp/unread') === 0) {
        $file = 'unread';
    } elseif ($path === '/api/wp/tickets' && $method === 'POST') {
        $file = 'ticket-created';
    } elseif (preg_match('#/api/wp/tickets/[^/]+/read$#', $path) && $method === 'POST') {
        return ['unread_total' => 0];
    } elseif (preg_match('#/api/wp/tickets/[^/]+/messages$#', $path) && $method === 'POST') {
        $file = 'message-created';
    } elseif (preg_match('#/api/wp/tickets/[^/]+/actions$#', $path) && $method === 'POST') {
        $file = 'ticket-detail';
    } elseif (preg_match('#/api/wp/tickets/([^/]+)$#', $path, $m)) {
        // 起票した直後の1件は、起票の応答（詳細と同じ形）を返す。
        // 1本しか返さないと、作ったはずの依頼が別物に見えてしまう。
        $created = hb_support_mock_read('ticket-created');
        $file    = (is_array($created) && ($created['id'] ?? '') === $m[1]) ? 'ticket-created' : 'ticket-detail';
    }

    $data = hb_support_mock_read($file);

    return is_array($data) ? $data : new WP_Error('server_error', '', ['status' => 500]);
}

/**
 * フィクスチャを1本読む。
 *
 * @return array<string,mixed>|null
 */
function hb_support_mock_read(string $name): ?array
{
    $json = @file_get_contents(HB_BASE_DIR . '/docs/hb-support/fixtures/' . $name . '.json');

    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}

/**
 * エラーの見え方を確かめるためのフィクスチャ。
 *
 * @return array<string,array<string,mixed>>
 */
function hb_support_error_fixtures(): array
{
    $json = @file_get_contents(HB_BASE_DIR . '/docs/hb-support/fixtures/errors.json');
    $data = $json === false ? null : json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    unset($data['_note']);

    return $data;
}
