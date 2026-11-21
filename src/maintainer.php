<?php
/**
 * 「保守担当」を見分ける
 *
 * クライアントの管理者と、サイトを作って面倒を見ている側は、
 * WordPress上はどちらも「管理者」で区別が付かない。
 * 保守用の道具（ログイン制限の設定画面など）はこちらだけに見せたいので、
 * 名指しで登録できるようにする。
 *
 *   define('HB_MAINTAINERS', 'design.hb149@gmail.com');
 *   define('HB_MAINTAINERS', 'design.hb149@gmail.com, hb-support');  // 複数可
 *
 * メールアドレス・ログイン名・ユーザーIDのどれでも書ける。
 * 未設定なら誰も保守担当にならない（＝全員クライアント扱い）。
 * 権限そのものは一切変えない。見せるかどうかだけを決める。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * このユーザーは保守担当か。
 *
 * @param int|null $user_id 省略時はログイン中のユーザー
 */
function hb_is_maintainer(?int $user_id = null): bool
{
    $list = hb_base_to_list(hb_base_const('HB_MAINTAINERS', ''));

    if (empty($list)) {
        return false;
    }

    if ($user_id === null) {
        $user_id = hb_current_user_id_early();
    }

    $user = $user_id > 0 ? get_user_by('id', $user_id) : null;

    if (!$user || !$user->exists()) {
        return false;
    }

    foreach ($list as $who) {
        if (ctype_digit($who)) {
            if ((int) $who === (int) $user->ID) {
                return true;
            }
            continue;
        }

        if (strcasecmp($who, (string) $user->user_login) === 0
            || strcasecmp($who, (string) $user->user_email) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * ログイン中のユーザーID。init より前でも答えられる。
 *
 * wp_get_current_user() は init 以降でないと当てにならないが、
 * プラグインによっては plugins_loaded の時点でフックを張るかどうかを
 * 決めてしまう（Limit Login Attempts のツールバー項目など）。
 * その判断に間に合わせるため、ログインCookieから直接読む。
 */
function hb_current_user_id_early(): int
{
    if (did_action('init')) {
        return (int) get_current_user_id();
    }

    static $id = null;

    if ($id === null) {
        $id = (int) wp_validate_auth_cookie('', 'logged_in');
    }

    return $id;
}
