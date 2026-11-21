<?php
/**
 * Limit Login Attempts Reloaded の初期設定をそろえる
 *
 * ■ やること
 *   新しい案件でこのプラグインを有効にしたとき、設定が未入力のものだけに
 *   こちらの値を書き込む。入れ忘れ・緩めっぱなしを防ぐのが目的。
 *
 * ■ やらないこと
 *   固定はしない。書き込んだあとは管理画面から自由に変更できるし、
 *   こちらが上書きし直すこともない。あくまで出発点をそろえるだけ。
 *
 *   すでに値が入っているキーには触れない。
 *   運用中のサイトに後からこのプラグインを入れても、設定は変わらない。
 *
 * ■ 保守担当には見せる
 *   管理画面の道具（左メニュー・ツールバー・ダッシュボード）は既定で隠すが、
 *   HB_MAINTAINERS に登録した人が見ているあいだだけ表示に戻す。
 *   DBの値は書き換えないので、クライアントから見た状態は変わらない。
 *
 * ■ 案件で初期値を変えたいとき
 *   wp-config.php で、変えたいキーだけ書く（投入より前に書くこと）。
 *     define('HB_LLAR_SETTINGS', ['allowed_retries' => 3, 'lockout_duration' => 1800]);
 *   投入そのものを止めるなら
 *     define('HB_LLAR_MANAGE', false);
 *
 * ■ もう一度投入したいとき
 *   wp option delete hb_llar_seeded
 *   （このときも、すでに値があるキーは飛ばす）
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_LLAR_PREFIX = 'limit_login_';
const HB_LLAR_MARKER = 'hb_llar_seeded';

function hb_limit_login_init(): void
{
    if (!hb_base_const('HB_LLAR_MANAGE', true)) {
        return;
    }

    // 管理画面を開いたときに一度だけ見る。表示側では何もしない。
    add_action('admin_init', 'hb_limit_login_seed');

    // 保守担当にだけ、隠した管理画面の道具を見せ戻す。
    foreach (array_keys(hb_limit_login_ui_keys()) as $key) {
        add_filter('pre_option_' . HB_LLAR_PREFIX . $key, 'hb_limit_login_show_for_maintainer', 10, 2);
    }
}

/**
 * 管理画面での見せかたに関わるキーと、保守担当に見せるときの値。
 */
function hb_limit_login_ui_keys(): array
{
    return [
        'show_top_level_menu_item' => 1,
        'show_top_bar_menu_item'   => 1,
        'hide_dashboard_widget'    => 0,
        'show_warning_badge'       => 1,
    ];
}

/**
 * 保守担当が見ているあいだだけ、保存値の代わりに「表示する」を返す。
 *
 * DBの値は書き換えない。クライアントから見た状態は変わらない。
 *
 * ただしこのプラグイン自身の設定画面では何もしない。
 * チェックボックスに実際と違う状態が出てしまい、
 * そのまま保存すると全員に表示される設定に化けるため。
 *
 * @param mixed  $pre    false のままならDBの値を使う
 * @param string $option オプション名
 * @return mixed
 */
function hb_limit_login_show_for_maintainer($pre, $option = '')
{
    if (!is_admin() || !hb_is_maintainer()) {
        return $pre;
    }

    // 設定画面は保存値をそのまま映す。
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    if (strpos($page, 'limit-login-attempts') === 0) {
        return $pre;
    }

    $key  = substr((string) $option, strlen(HB_LLAR_PREFIX));
    $keys = hb_limit_login_ui_keys();

    return array_key_exists($key, $keys) ? $keys[$key] : $pre;
}

/**
 * 未設定のキーにだけ初期値を書き込む。
 */
function hb_limit_login_seed(): void
{
    if (get_option(HB_LLAR_MARKER)) {
        return;
    }

    // プラグインが読み込まれていなければ何もしない。
    // 本体が自分の定数を定義しているかどうかで見る。
    if (!defined('LLA_DIRECT_ADDR')) {
        return;
    }

    $written = [];

    foreach (hb_limit_login_settings() as $key => $value) {
        $name = HB_LLAR_PREFIX . $key;

        // すでに誰かが決めた値には触らない。
        if (get_option($name, null) !== null) {
            continue;
        }

        // update_option は「値が今と同じなら書かない」ので、未作成のオプションに
        // false を渡すと（get_option の既定も false のため）黙って無視される。
        // 新規作成の意図どおり add_option を使う。
        add_option($name, $value, '', false);
        $written[] = $key;
    }

    // 件数が0でも印を付ける。毎回ここまで来る必要はない。
    update_option(HB_LLAR_MARKER, [
        'at'      => time(),
        'version' => HB_BASE_VERSION,
        'written' => $written,
    ], false);
}

/**
 * 初期値。
 *
 * 並べるのは「案件ごとに違うと困るもの」だけ。
 * ホワイトリストやMFAなど、サイトの事情で決まるものは触らない。
 */
function hb_limit_login_defaults(): array
{
    return [
        /* ---- 試行制限 ---- */
        // 4回失敗で20分ロック。4回ロックされたら24時間ロック。
        // 打ち間違いで締め出さず、総当たりには十分遅い落としどころ。
        'allowed_retries'  => 4,
        'lockout_duration' => 20 * MINUTE_IN_SECONDS,
        'allowed_lockouts' => 4,
        'long_duration'    => DAY_IN_SECONDS,
        'valid_duration'   => DAY_IN_SECONDS,

        // 偽装されたログインCookieも試行として数える。
        // 値は 0/1 で入れる。このプラグインが自分のフォームから保存する形に合わせる。
        'cookies' => 1,

        /* ---- 接続元の判定 ----
         * 転送ヘッダを無条件に信じると、攻撃側がヘッダを変えるだけで
         * 毎回「別のIP」になりロックを回避できる。
         * hb-base でプロキシを登録した案件でだけ転送ヘッダを見る。 */
        'client_type' => hb_trusted_proxies() ? 'HTTP_X_FORWARDED_FOR' : 'REMOTE_ADDR',

        /* ---- 通知 ---- */
        // ロックが続くときだけ管理者に知らせる。1回ごとだと多すぎて読まれなくなる。
        'lockout_notify'     => 'email',
        'notify_email_after' => 3,
        'digest_daily'       => 0,
        'digest_weekly'      => 0,
        'digest_monthly'     => 0,

        /* ---- 管理画面の見せかた ----
         * クライアントが毎日使う画面なので、保守用のプラグインが
         * 左メニュー・ツールバー・ダッシュボードを占領しないようにする。
         * 設定画面は「設定」の中に残るので、こちらからは普通に開ける。 */
        'show_top_level_menu_item' => 0,
        'show_top_bar_menu_item'   => 0,
        'hide_dashboard_widget'    => 1,
        'show_warning_badge'       => 0,
    ];
}

/** 初期値に、案件ごとの上書きを重ねたもの。 */
function hb_limit_login_settings(): array
{
    $settings = hb_limit_login_defaults();

    $override = hb_base_const('HB_LLAR_SETTINGS', []);

    if (is_array($override)) {
        // 知らないキーは増やさない。綴り間違いが黙って通ると追えなくなる。
        $settings = array_merge($settings, array_intersect_key($override, $settings));
    }

    return apply_filters('hb_llar_settings', $settings);
}
