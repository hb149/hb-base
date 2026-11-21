<?php
/**
 * 端末の識別と許可
 *
 * 固定IPが取れない回線（クライアントの自宅・スマホ・出張先）からでも
 * 管理者が「この端末だけ」を通せるようにするための仕組み。
 *
 * 流れは2通り:
 *   1. 招待  … 管理者がURLを発行 → クライアントがその端末で開く → 信頼済みになる
 *   2. 申請  … 遮断画面からクライアントが申請 → 管理者が管理画面で承認する
 *
 * 端末の見分けはCookieに入れた乱数IDで行う。IDそのものに権限は無く、
 * サーバー側の一覧で「承認済みか」を引く。だからIDが漏れても、
 * 管理者が承認していない限り通らないし、いつでも取り消せる。
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_DEVICE_COOKIE = 'hb_device';
const HB_DEVICE_OPTION = 'hb_base_devices';
const HB_DEVICE_MAX    = 200;

/** Cookieの改ざん検出用。WPのソルトを使うので追加の設定は要らない。 */
function hb_device_sign(string $id): string
{
    return hash_hmac('sha256', 'hb-device|' . $id, wp_salt('auth'));
}

/** Cookieにある端末ID。署名が合わなければ空を返す。 */
function hb_device_current_id(): string
{
    if (empty($_COOKIE[HB_DEVICE_COOKIE])) {
        return '';
    }

    $raw = (string) $_COOKIE[HB_DEVICE_COOKIE];

    if (substr_count($raw, '.') !== 1) {
        return '';
    }

    [$id, $sig] = explode('.', $raw, 2);

    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        return '';
    }

    return hash_equals(hb_device_sign($id), $sig) ? $id : '';
}

/** 端末IDを用意する。無ければ発行してCookieに入れる。 */
function hb_device_ensure_id(): string
{
    $id = hb_device_current_id();

    if ($id !== '') {
        return $id;
    }

    $id = bin2hex(random_bytes(16));
    hb_device_set_cookie($id);

    // 同じリクエスト内で読み直せるようにしておく。
    $_COOKIE[HB_DEVICE_COOKIE] = $id . '.' . hb_device_sign($id);

    return $id;
}

function hb_device_set_cookie(string $id): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(HB_DEVICE_COOKIE, $id . '.' . hb_device_sign($id), [
        'expires'  => time() + (hb_device_ttl_days() * DAY_IN_SECONDS),
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** 保存されている端末一覧。 */
function hb_devices_all(): array
{
    $devices = get_option(HB_DEVICE_OPTION, []);

    return is_array($devices) ? $devices : [];
}

function hb_devices_save(array $devices): void
{
    // 増えすぎないように、古い「却下・期限切れ」から捨てる。
    if (count($devices) > HB_DEVICE_MAX) {
        uasort($devices, static function ($a, $b) {
            return ($a['last_seen'] ?? 0) <=> ($b['last_seen'] ?? 0);
        });

        foreach ($devices as $id => $device) {
            if (count($devices) <= HB_DEVICE_MAX) {
                break;
            }
            if (($device['status'] ?? '') !== 'trusted') {
                unset($devices[$id]);
            }
        }
    }

    update_option(HB_DEVICE_OPTION, $devices, false);
}

function hb_device_get(string $id): ?array
{
    $devices = hb_devices_all();

    return isset($devices[$id]) && is_array($devices[$id]) ? $devices[$id] : null;
}

/** 承認済みで、まだ期限内か。 */
function hb_device_is_trusted(string $id): bool
{
    if ($id === '') {
        return false;
    }

    $device = hb_device_get($id);

    if ($device === null || ($device['status'] ?? '') !== 'trusted') {
        return false;
    }

    $expires = (int) ($device['expires'] ?? 0);

    return $expires === 0 || $expires > time();
}

/** 端末を承認する。招待URL・申請の承認・緊急解除のいずれからも呼ぶ。 */
function hb_device_trust(string $id, string $label = '', string $via = 'manual'): void
{
    $devices = hb_devices_all();
    $now     = time();
    $device  = $devices[$id] ?? [];

    $devices[$id] = array_merge($device, [
        'status'    => 'trusted',
        'label'     => $label !== '' ? $label : (string) ($device['label'] ?? ''),
        'ip'        => hb_client_ip(),
        'ua'        => hb_device_ua(),
        'created'   => (int) ($device['created'] ?? $now),
        'approved'  => $now,
        'approver'  => get_current_user_id(),
        'via'       => $via,
        'expires'   => $now + (hb_device_ttl_days() * DAY_IN_SECONDS),
        'last_seen' => $now,
    ]);

    hb_devices_save($devices);
}

/** 承認を取り消す。記録は残す（誰がいつ使っていたか追えるように）。 */
function hb_device_revoke(string $id): void
{
    $devices = hb_devices_all();

    if (!isset($devices[$id])) {
        return;
    }

    $devices[$id]['status']  = 'revoked';
    $devices[$id]['expires'] = 0;
    $devices[$id]['revoked'] = time();

    hb_devices_save($devices);
}

function hb_device_delete(string $id): void
{
    $devices = hb_devices_all();
    unset($devices[$id]);
    hb_devices_save($devices);
}

/** 遮断画面からの申請を受け付ける。 */
function hb_device_request(string $id, string $name, string $note): void
{
    $devices = hb_devices_all();
    $now     = time();
    $device  = $devices[$id] ?? [];

    // すでに承認済みなら何もしない。
    if (($device['status'] ?? '') === 'trusted') {
        return;
    }

    $devices[$id] = array_merge($device, [
        'status'    => 'pending',
        'label'     => $name,
        'note'      => $note,
        'ip'        => hb_client_ip(),
        'ua'        => hb_device_ua(),
        'created'   => (int) ($device['created'] ?? $now),
        'requested' => $now,
        'last_seen' => $now,
    ]);

    hb_devices_save($devices);
}

/** 最終利用日時の記録。毎回書くとDBが忙しいので1時間に1回だけ。 */
function hb_device_touch(string $id): void
{
    $devices = hb_devices_all();

    if (!isset($devices[$id])) {
        return;
    }

    if ((time() - (int) ($devices[$id]['last_seen'] ?? 0)) < HOUR_IN_SECONDS) {
        return;
    }

    $devices[$id]['last_seen'] = time();
    $devices[$id]['ip']        = hb_client_ip();

    hb_devices_save($devices);
}

/** ブラウザ名。一覧で端末を見分けるためだけに使う。 */
function hb_device_ua(): string
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

    return mb_substr(wp_strip_all_tags($ua), 0, 255);
}

/* ---------------------------------------------------------------------------
 * 招待URL
 * -------------------------------------------------------------------------*/

/**
 * 1回だけ使える招待トークンを作る。
 * 短命・使い捨てにしてあるので、URLが後から漏れても再利用できない。
 */
function hb_device_invite_create(string $label, int $ttl = 1800): string
{
    $token = bin2hex(random_bytes(24));

    set_transient('hb_invite_' . hash('sha256', $token), [
        'label'   => $label,
        'issued'  => time(),
        'by'      => get_current_user_id(),
    ], max(300, $ttl));

    return $token;
}

/**
 * 招待トークンを使う。成功したらラベルを返し、トークンは消す。
 */
function hb_device_invite_consume(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }

    $key  = 'hb_invite_' . hash('sha256', $token);
    $data = get_transient($key);

    if (!is_array($data)) {
        return null;
    }

    delete_transient($key);

    return $data;
}

/** 招待URL。管理画面以外のURLにしてある（遮断対象の外から開けるように）。 */
function hb_device_invite_url(string $token): string
{
    return add_query_arg('hb-device-invite', $token, home_url('/'));
}
