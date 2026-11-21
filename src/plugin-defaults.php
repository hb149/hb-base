<?php
/**
 * 標準プラグインの初期設定
 *
 * ■ 考えかた（Limit Login Attempts と同じ）
 *   固定はしない。初期値を入れるだけで、以後は管理画面から自由に変えられる。
 *
 *   さらに安全側に倒して、**そのプラグインの既定値のままのキーだけ**書き換える。
 *   誰かが一度でも触った値には手を出さないので、運用中のサイトに後から入れても
 *   設定が勝手に変わることはない。
 *
 * ■ 直しているのは「既定のままだと困る」ものだけ
 *   好みの問題は触らない。
 *
 * ■ 止めかた
 *   define('HB_PLUGIN_DEFAULTS', false);
 */

if (!defined('ABSPATH')) {
    exit;
}

const HB_DEFAULTS_MARKER = 'hb_plugin_defaults_seeded';

function hb_plugin_defaults_init(): void
{
    if (!hb_base_const('HB_PLUGIN_DEFAULTS', true)) {
        return;
    }

    // 投稿タイプが出そろってから判断したいので admin_init で。
    add_action('admin_init', 'hb_plugin_defaults_seed', 20);

    // Public Post Preview は設定を持たずフィルタで決まるので、常に適用する。
    hb_plugin_defaults_public_post_preview();
}

/** 複製やプレビューの対象にしたい投稿タイプ。公開されていて、添付ファイルでないもの。 */
function hb_plugin_defaults_target_post_types(): array
{
    $types = get_post_types(['public' => true], 'names');

    unset($types['attachment']);

    return array_values($types);
}

function hb_plugin_defaults_seed(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (get_option(HB_DEFAULTS_MARKER)) {
        return;
    }

    $done = [];

    $done['duplicate-post']  = hb_plugin_defaults_duplicate_post();
    $done['seo-simple-pack'] = hb_plugin_defaults_seo_simple_pack();

    update_option(HB_DEFAULTS_MARKER, [
        'at'      => time(),
        'version' => HB_BASE_VERSION,
        'changed' => array_filter($done),
    ], false);
}

/* ---------------------------------------------------------------------------
 * Yoast Duplicate Post
 * -------------------------------------------------------------------------*/

/**
 * 既定では「投稿」と「固定ページ」しか複製できない。
 * 分譲地や施工事例こそ複製したいので、公開中の投稿タイプを全部対象にする。
 *
 * @return string[] 変更したキー
 */
function hb_plugin_defaults_duplicate_post(): array
{
    if (!defined('DUPLICATE_POST_CURRENT_VERSION') && !function_exists('duplicate_post_plugin_upgrade')) {
        return [];
    }

    $changed = [];

    // 手つかず（post と page だけ）のときにだけ広げる。
    $current = get_option('duplicate_post_types_enabled');
    $stock   = ['post', 'page'];

    if (is_array($current) && array_values($current) === $stock) {
        update_option('duplicate_post_types_enabled', hb_plugin_defaults_target_post_types(), false);
        $changed[] = 'duplicate_post_types_enabled';
    }

    // 複製元が分かるように一覧へ列を出す。どれが複製か追えないと運用で迷子になる。
    if (get_option('duplicate_post_show_original_column') === '0') {
        update_option('duplicate_post_show_original_column', '1', false);
        $changed[] = 'duplicate_post_show_original_column';
    }

    return $changed;
}

/* ---------------------------------------------------------------------------
 * SEO SIMPLE PACK
 * -------------------------------------------------------------------------*/

/**
 * 既定のままだと困るところだけ直す。
 *
 * @return string[] 変更したキー
 */
function hb_plugin_defaults_seo_simple_pack(): array
{
    $settings = get_option('ssp_settings');

    if (!is_array($settings)) {
        return [];
    }

    $changed = [];

    foreach (hb_plugin_defaults_ssp_map() as $key => $spec) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }

        // そのプラグインの既定値のままのときだけ書き換える。
        if ((string) $settings[$key] !== (string) $spec['from']) {
            continue;
        }

        $settings[$key] = $spec['to'];
        $changed[]      = $key;
    }

    if (!empty($changed)) {
        update_option('ssp_settings', $settings, true);
    }

    return $changed;
}

/**
 * 「既定値 → 入れたい値」の対応。
 *
 * 投稿タイプやタクソノミーごとのキー（property_noindex など）はサイトによって
 * 有無が変わるので、ここでは触らない。案件側で決めること。
 */
function hb_plugin_defaults_ssp_map(): array
{
    return apply_filters('hb_ssp_defaults', [
        /* カスタム投稿タイプのアーカイブが既定で noindex になっている。
           分譲地一覧や施工事例一覧は検索から入ってほしいページなので、索引させる。 */
        'pt_archive_noindex' => ['from' => '1', 'to' => ''],

        /* 説明文の既定が英語のまま。日本語サイトの検索結果に英文が出てしまう。 */
        'pt_archive_desc' => [
            'from' => 'This is the archive page for %_post_type_%.',
            'to'   => '%_post_type_%の一覧です。',
        ],
        'author_desc' => [
            'from' => 'This is the archive page for %_author_name_%.',
            'to'   => '%_author_name_%の投稿一覧です。',
        ],
        'date_desc' => [
            'from' => 'This is the archive page for %_date_%.',
            'to'   => '%_date_%の投稿一覧です。',
        ],

        /* タイトルも同様。404と検索結果は利用者の目に触れる。 */
        '404_title' => [
            'from' => '404: Page not found %_sep_% %_site_title_%',
            'to'   => 'ページが見つかりません %_sep_% %_site_title_%',
        ],
        'search_title' => [
            'from' => 'Searched: %_search_phrase_% %_sep_% %_site_title_%',
            'to'   => '「%_search_phrase_%」の検索結果 %_sep_% %_site_title_%',
        ],

        /* フィードが索引対象になっている。本文の重複として扱われるので外す。 */
        'feed_noindex' => ['from' => '', 'to' => '1'],
    ]);
}

/* ---------------------------------------------------------------------------
 * Public Post Preview
 * -------------------------------------------------------------------------*/

/**
 * このプラグインは設定画面を持たず、フィルタで決まる。
 * 「全案件で同じ」にしたいので土台側で指定する。
 */
function hb_plugin_defaults_public_post_preview(): void
{
    add_filter('ppp_nonce_life', 'hb_plugin_defaults_ppp_life');
    add_filter('ppp_post_types', 'hb_plugin_defaults_ppp_post_types');
}

/**
 * プレビューリンクの有効期限。
 * 既定の48時間は「金曜に送って月曜に見る」で切れる。週をまたげる長さにする。
 */
function hb_plugin_defaults_ppp_life(): int
{
    $days = max(1, (int) hb_base_const('HB_PPP_DAYS', 7));

    return $days * DAY_IN_SECONDS;
}

/** 既定は投稿と固定ページだけ。分譲地などもクライアントに見せたい。 */
function hb_plugin_defaults_ppp_post_types(array $types): array
{
    return array_values(array_unique(array_merge($types, hb_plugin_defaults_target_post_types())));
}
