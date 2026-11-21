<?php
/**
 * クライアントに見せない管理画面の項目
 *
 * ■ ファイル編集の無効化
 *   管理画面から テーマ・プラグインのPHPを直接書き換えられる。
 *   乗っ取られたらその場でバックドアを置けるし、クライアントが誤って
 *   サイトを白紙にすることもある。使う理由がない。
 *
 *   本来は wp-config.php の DISALLOW_FILE_EDIT だが、案件ごとに書き忘れる。
 *   権限側で落としておけば、どの案件でも確実に無効になる。
 *   （定数が書いてあればそちらでも止まる。二重でも害はない）
 *
 * ■ メニューの整理
 *   パーマリンク設定のように「触ると全ページのURLが変わる」ものを
 *   クライアントの画面から外す。保守担当には出す。
 *
 *   ※ これは誤操作を減らすためのもので、権限は変わらない。
 *     URLを直接開けば届く。守りではなく整理。
 *
 * 止めるとき:
 *   define('HB_FILE_EDIT', true);   // ファイル編集を許す
 *   define('HB_CLIENT_HIDE', '');   // メニューを隠さない
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_client_ui_init(): void
{
    if (!hb_base_const('HB_FILE_EDIT', false)) {
        add_filter('map_meta_cap', 'hb_client_ui_block_file_edit', 10, 2);
    }

    if (is_admin()) {
        add_action('admin_menu', 'hb_client_ui_hide_menus', 999);
    }
}

/**
 * テーマ・プラグインのファイル編集を落とす。
 *
 * @param string[] $caps
 * @param string   $cap
 * @return string[]
 */
function hb_client_ui_block_file_edit($caps, $cap): array
{
    $blocked = ['edit_themes', 'edit_plugins', 'edit_files'];

    return in_array($cap, $blocked, true) ? ['do_not_allow'] : (array) $caps;
}

/**
 * クライアントの画面から外す項目。
 *
 * 「親メニュー|子メニュー」で指定する。子だけ外すこともできる。
 *
 * @return string[]
 */
function hb_client_ui_hidden(): array
{
    $hide = hb_base_const('HB_CLIENT_HIDE');

    if ($hide === null) {
        $hide = [
            // 触ると全ページのURLが変わる。直すまでサイト全体が404になる。
            'options-general.php|options-permalink.php',
            // 下の2つは権限を落としてあるので開いても何もできない。並べておく意味がない。
            'themes.php|theme-editor.php',
            'plugins.php|plugin-editor.php',
        ];
    }

    return hb_base_to_list($hide);
}

/** 保守担当以外からメニューを隠す。 */
function hb_client_ui_hide_menus(): void
{
    if (hb_is_maintainer()) {
        return;
    }

    foreach (hb_client_ui_hidden() as $item) {
        if (strpos($item, '|') !== false) {
            [$parent, $child] = explode('|', $item, 2);
            remove_submenu_page($parent, $child);
            continue;
        }

        remove_menu_page($item);
    }
}
