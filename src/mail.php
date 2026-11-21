<?php
/**
 * ローカルのメールを受け止める先へ向ける
 *
 * ■ なぜ必要か
 *   Docker の WordPress 公式イメージには sendmail が入っていない。
 *   そのため wp_mail() は常に失敗し、フォームの送信確認も
 *   パスワード再発行も、ローカルでは一切試せない。
 *   Mailpit などを立てていても、WordPress からは届かないまま。
 *
 * ■ やること
 *   SMTPで受け止め先へ直接渡す。
 *     define('HB_MAIL_CATCHER', 'mail:1025');   // ホスト:ポート
 *
 * ■ 本番では絶対に動かない
 *   wp_get_environment_type() が 'local' のときだけ有効にする。
 *   設定を本番に持ち込んでも、実メールが受け止め先に吸い込まれる事故は起きない。
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_mail_init(): void
{
    // 差出人の補正は本番でも効かせる。壊れたアドレスのまま送れることはないため。
    add_filter('wp_mail_from', 'hb_mail_fix_from');

    if (hb_mail_catcher() === null) {
        return;
    }

    add_action('phpmailer_init', 'hb_mail_use_catcher');
}

/**
 * 差出人アドレスが不正なときだけ直す。
 *
 * WordPress の既定は wordpress@<サイトのホスト名>。
 * ローカルだと wordpress@localhost になるが、ドメインに「.」が無いため
 * PHPMailer に不正アドレスとして弾かれ、メールが1通も出ない。
 *
 * 正しいアドレスには手を出さない。本番の差出人を勝手に変えると
 * SPF の整合が崩れて迷惑メール扱いされることがある。
 *
 * @param string $from
 */
function hb_mail_fix_from($from): string
{
    $from = (string) $from;

    if (is_email($from)) {
        return $from;
    }

    $fallback = (string) hb_base_const('HB_MAIL_FROM', 'wordpress@localhost.localdomain');

    return is_email($fallback) ? $fallback : 'wordpress@localhost.localdomain';
}

/**
 * 受け止め先。設定が無い・本番なら null。
 *
 * @return array{host:string,port:int}|null
 */
function hb_mail_catcher(): ?array
{
    $target = (string) hb_base_const('HB_MAIL_CATCHER', '');

    if ($target === '') {
        return null;
    }

    if (!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'local') {
        return null;
    }

    $parts = explode(':', $target);
    $host  = trim($parts[0]);
    $port  = isset($parts[1]) ? (int) $parts[1] : 1025;

    return $host === '' ? null : ['host' => $host, 'port' => $port];
}

/**
 * @param PHPMailer\PHPMailer\PHPMailer $mailer
 */
function hb_mail_use_catcher($mailer): void
{
    $catcher = hb_mail_catcher();

    if ($catcher === null) {
        return;
    }

    $mailer->isSMTP();
    $mailer->Host       = $catcher['host'];
    $mailer->Port       = $catcher['port'];
    $mailer->SMTPAuth   = false;
    $mailer->SMTPAutoTLS = false;   // 受け止め先は暗号化しない
    $mailer->SMTPSecure = '';
}

/** 管理画面に出す状態。 */
function hb_mail_summary(): string
{
    $catcher = hb_mail_catcher();

    if ($catcher !== null) {
        return sprintf('ローカルの受け止め先へ送信（<code>%s:%d</code>）', esc_html($catcher['host']), $catcher['port']);
    }

    if (hb_base_const('HB_MAIL_CATCHER', '') !== '') {
        return '<span class="description">受け止め先は設定済みだが、この環境は local ではないので通常送信</span>';
    }

    return '<span class="description">通常送信</span>';
}
