<?php
/**
 * 管理画面の宣伝パネルを出さない
 *
 * プラグインを入れるたびに出る「インストールに成功しました！ニュースレターを購読」
 * 「レビューを書いてください」の類を、全案件で同じように黙らせる。
 *
 * ■ 2段構え
 *   1. フラグ … そのプラグイン自身が持っている「表示済み」の印を先に立てる。
 *      一番確実で、プラグインの作りに沿っている。分かっているものはこちら。
 *   2. 名前で除去 … 通知フックに登録された関数名が宣伝っぽければ外す。
 *      新しく入れたプラグインにも効く。HTMLを見て判断するより安全で、
 *      外し損ねても画面が壊れることはない。
 *
 * ■ 消さないもの
 *   WordPress本体の警告（更新・メンテナンス・初期パスワード・復旧モード）、
 *   プラグインの不具合通知、こちらが出している通知。
 *   関数名で判断しているので、本体の通知に巻き添えが出ない。
 *
 * ダッシュボードの「WordPress へようこそ！」も同じ理由で消す。
 * 納品後のクライアントには用の無い案内で、毎回「非表示」を押させることになる。
 *
 * ■ 設定
 *   define('HB_HIDE_PROMO_NOTICES', false);                  // 丸ごと止める
 *   define('HB_PROMO_NOTICE_WORDS', 'review, newsletter');   // 判断する語を差し替える
 *   define('HB_HIDE_WELCOME_PANEL', false);                  // ようこそパネルは残す
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_NOTICE_MARKER = 'hb_notice_flags_seeded';

function hb_admin_notices_init(): void
{
    if (!hb_base_const('HB_HIDE_PROMO_NOTICES', true)) {
        return;
    }

    add_action('admin_init', 'hb_admin_notices_seed');

    // 通知が出力されるより前に外す。
    // admin_notices の中で外そうとすると、WordPressがすでに回し始めているので間に合わない。
    add_action('in_admin_header', 'hb_admin_notices_strip', 1);

    if (hb_base_const('HB_HIDE_WELCOME_PANEL', true)) {
        add_action('in_admin_header', 'hb_admin_notices_hide_welcome', 1);
    }
}

/**
 * ダッシュボードの「WordPress へようこそ！」を出さない。
 *
 * wp_welcome_panel は wp-admin/includes/dashboard.php が読み込まれた時点で
 * 登録される。plugins_loaded の段階ではまだ居ないので外せない。
 * 画面の組み立てが始まる in_admin_header まで待つ。
 *
 * ユーザーごとの「非表示」設定（show_welcome_panel）には触らない。
 */
function hb_admin_notices_hide_welcome(): void
{
    remove_action('welcome_panel', 'wp_welcome_panel');
}

/* ---------------------------------------------------------------------------
 * 1. プラグイン自身の「表示済み」フラグを立てる
 * -------------------------------------------------------------------------*/

/**
 * プラグインが歓迎パネルの表示可否を見ているオプション。
 *
 * 'option' か 'site_option' と、黙らせる値。
 * プラグインを増やしたらここに足す。該当プラグインが無ければ素通りする。
 */
function hb_admin_notices_flags(): array
{
    return apply_filters('hb_admin_notice_flags', [
        // Yoast Duplicate Post の「インストールに成功しました！」
        'duplicate_post_show_notice' => ['type' => 'site_option', 'value' => 0],
    ]);
}

/**
 * 一度だけ立てる。
 * 立てたあとに誰かが戻したなら、その判断を尊重して二度と触らない。
 */
function hb_admin_notices_seed(): void
{
    if (get_option(HB_NOTICE_MARKER)) {
        return;
    }

    foreach (hb_admin_notices_flags() as $name => $spec) {
        if (($spec['type'] ?? 'option') === 'site_option') {
            update_site_option($name, $spec['value']);
        } else {
            update_option($name, $spec['value'], false);
        }
    }

    update_option(HB_NOTICE_MARKER, ['at' => time(), 'version' => HB_BASE_VERSION], false);
}

/* ---------------------------------------------------------------------------
 * 2. 関数名で外す
 * -------------------------------------------------------------------------*/

/** 宣伝とみなす語。関数名・クラス名に含まれていたら外す。 */
function hb_admin_notices_words(): array
{
    $words = hb_base_const('HB_PROMO_NOTICE_WORDS');

    if ($words === null) {
        return [
            'review',       // 「レビューを書いてください」
            'newsletter',
            'rating',
            'upsell',
            'cross_sell',
            'promo',
            'black_friday',
            'bfcm',
            'discount',
            'donate',
            'survey',
            'go_pro',
            'gopro',
            'premium_notice',
        ];
    }

    return hb_base_to_list($words);
}

/** 通知フックから宣伝っぽい登録を外す。 */
function hb_admin_notices_strip(): void
{
    global $wp_filter;

    $words = hb_admin_notices_words();

    if (empty($words)) {
        return;
    }

    $hooks = ['admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices'];

    foreach ($hooks as $hook) {
        if (empty($wp_filter[$hook])) {
            continue;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $name = hb_admin_notices_callback_name($callback['function']);

                if ($name === '' || !hb_admin_notices_is_promo($name, $words)) {
                    continue;
                }

                remove_action($hook, $callback['function'], $priority);
            }
        }
    }
}

/**
 * 登録されたものの名前を取り出す。
 * 無名関数は名前が無いので判断できない。触らない。
 *
 * @param mixed $function
 */
function hb_admin_notices_callback_name($function): string
{
    if (is_string($function)) {
        return $function;
    }

    if (is_array($function) && count($function) === 2) {
        $class = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];

        return $class . '::' . (string) $function[1];
    }

    return '';
}

function hb_admin_notices_is_promo(string $name, array $words): bool
{
    $name = strtolower($name);

    // 自分の通知は外さない。
    if (strpos($name, 'hb_') === 0) {
        return false;
    }

    foreach ($words as $word) {
        if ($word !== '' && strpos($name, strtolower($word)) !== false) {
            return true;
        }
    }

    return false;
}
