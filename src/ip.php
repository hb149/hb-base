<?php
/**
 * 接続元IPの判定
 *
 * 転送ヘッダ（X-Forwarded-For / CF-Connecting-IP）は誰でも偽装できるので、
 * HB_TRUSTED_PROXIES に挙げたCIDRから来たときだけ読む。
 * 既定では一切読まず REMOTE_ADDR だけを見る。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** TCPの接続元。これだけは偽装できない。 */
function hb_remote_addr(): string
{
    $addr = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    return filter_var($addr, FILTER_VALIDATE_IP) ? $addr : '';
}

/**
 * 利用者のIP。プロキシ経由なら転送ヘッダの値、そうでなければ接続元。
 */
function hb_client_ip(): string
{
    $remote = hb_remote_addr();

    if ($remote === '' || !hb_ip_in_list($remote, hb_trusted_proxies())) {
        return $remote;
    }

    // Cloudflare はここに元IPを入れる。XFF より素直なので先に見る。
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 「元IP, プロキシ1, プロキシ2」の順。先頭が利用者。
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return $remote;
}

/**
 * IPが一覧（単体IPまたはCIDR）のどれかに含まれるか。
 */
function hb_ip_in_list(string $ip, array $list): bool
{
    if ($ip === '' || empty($list)) {
        return false;
    }

    foreach ($list as $entry) {
        if (hb_ip_matches($ip, $entry)) {
            return true;
        }
    }

    return false;
}

/**
 * 単体IP（192.0.2.10）とCIDR（192.0.2.0/24, 2001:db8::/32）の両方を判定する。
 */
function hb_ip_matches(string $ip, string $entry): bool
{
    $entry = trim($entry);

    if ($entry === '' || $ip === '') {
        return false;
    }

    if (strpos($entry, '/') === false) {
        return hb_ip_normalize($ip) === hb_ip_normalize($entry);
    }

    [$subnet, $bits] = explode('/', $entry, 2);

    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton(trim($subnet));

    // IPv4 と IPv6 は長さが違う。混ざっていたら不一致。
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $bits = (int) $bits;
    $maxBits = strlen($ipBin) * 8;

    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $wholeBytes = intdiv($bits, 8);
    $remainder  = $bits % 8;

    if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
        return false;
    }

    if ($remainder === 0) {
        return true;
    }

    // 端数ビットは上位側だけを比べる。
    $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

    return (($ipBin[$wholeBytes] & $mask) === ($subnetBin[$wholeBytes] & $mask));
}

/**
 * 表記ゆれ（IPv6 の省略形など）を吸収して比較できる形にする。
 */
function hb_ip_normalize(string $ip): string
{
    $bin = @inet_pton(trim($ip));

    return $bin === false ? trim($ip) : bin2hex($bin);
}
