<?php
/**
 * 設定の読み取り
 *
 * 設定はすべて wp-config.php の定数で行う。DBに秘密を置かないので、
 * 案件のDBをコピーしても鍵が付いて回らず、本番とローカルで違う値を使える。
 * 管理画面からは変更できない（状態の表示と端末の許可だけ管理画面で行う）。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 定数が定義されていればその値、無ければ既定値。
 *
 * @param mixed $default
 * @return mixed
 */
function hb_base_const(string $name, $default = null)
{
    return defined($name) ? constant($name) : $default;
}

/**
 * カンマ区切りの文字列でも配列でも受け取れるようにする。
 * wp-config.php に配列リテラルを書くのは読みにくいので文字列も許す。
 *
 * @param mixed $value
 * @return string[]
 */
function hb_base_to_list($value): array
{
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $items = preg_split('/[\s,]+/', $value) ?: [];
    } else {
        return [];
    }

    $out = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $out[] = $item;
        }
    }

    return $out;
}

/**
 * モジュールの有効判定。
 * HB_BASE_MODULES を定義すると、挙げたものだけが動く。
 */
function hb_base_module_enabled(string $module): bool
{
    $modules = hb_base_const('HB_BASE_MODULES');

    if ($modules === null) {
        return true;
    }

    return in_array($module, hb_base_to_list($modules), true);
}

/**
 * 管理画面ガードの動作モード。
 *   off  … 何もしない（既定）
 *   log  … 遮断はせず、遮断したはずの要求を記録するだけ。導入時の様子見用
 *   on   … 遮断する
 *
 * 既定を off にしてあるのは、設定前のサイトにこのプラグインを入れただけで
 * 締め出される事故を防ぐため。
 */
function hb_admin_guard_mode(): string
{
    $mode = strtolower((string) hb_base_const('HB_ADMIN_GUARD', 'off'));

    return in_array($mode, ['off', 'log', 'on'], true) ? $mode : 'off';
}

/** 許可IP（CIDR可・IPv4/IPv6）。空なら端末許可だけで運用する。 */
function hb_admin_allow_ips(): array
{
    return hb_base_to_list(hb_base_const('HB_ADMIN_ALLOW_IPS', ''));
}

/** 端末を信頼し続ける日数。 */
function hb_device_ttl_days(): int
{
    return max(1, (int) hb_base_const('HB_ADMIN_DEVICE_DAYS', 180));
}

/**
 * 緊急解除キー。
 * ?hb-unlock=<キー> を付けて開くと、その端末を信頼済みにして通す。
 * 締め出された時の最後の逃げ道なので、長いランダム文字列にすること。
 */
function hb_admin_unlock_key(): string
{
    return (string) hb_base_const('HB_ADMIN_GUARD_UNLOCK_KEY', '');
}

/** 信頼するリバースプロキシのCIDR。ここに該当する接続元のときだけ転送ヘッダを見る。 */
function hb_trusted_proxies(): array
{
    return hb_base_to_list(hb_base_const('HB_TRUSTED_PROXIES', ''));
}

/** Turnstile のサイトキー（公開側）。 */
function hb_turnstile_site_key(): string
{
    return (string) hb_base_const('HB_TURNSTILE_SITE_KEY', '');
}

/** Turnstile のシークレットキー（サーバー側）。 */
function hb_turnstile_secret_key(): string
{
    return (string) hb_base_const('HB_TURNSTILE_SECRET_KEY', '');
}

/** Turnstile を出すフォーム。 */
function hb_turnstile_forms(): array
{
    $forms = hb_base_const('HB_TURNSTILE_FORMS');

    if ($forms === null) {
        return ['login', 'lostpassword', 'register'];
    }

    return hb_base_to_list($forms);
}

/**
 * Cloudflare に問い合わせできなかったときに通すかどうか。
 * ローカル開発では外に出られないことがあるので既定で通す。
 * 本番は既定で止める（通すと検証を迂回されるため）。
 */
function hb_turnstile_fail_open(): bool
{
    $value = hb_base_const('HB_TURNSTILE_FAIL_OPEN');

    if ($value === null) {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'local';
    }

    return (bool) $value;
}
