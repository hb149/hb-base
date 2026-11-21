<?php
/**
 * 著者ページを出さない
 *
 * ■ なぜ既定で消すか
 *   企業サイトでは書き手が1〜2人しかおらず、/author/xxx/ は
 *   トップや一覧と中身がほとんど同じ薄いページになる。検索に出す価値がない。
 *
 *   それ以上に、**このURLはログイン名を外に晒す**。
 *   /?author=1 を開くと WordPress が /author/<ログイン名>/ に転送するので、
 *   誰でも管理者のログイン名を1アクセスで調べられる。
 *   総当たりの出発点になるので、塞いでおく。
 *
 * ■ ホームへ転送せず404にする理由
 *   中身の違うURLを一律でトップに飛ばすと、検索エンジンからは
 *   「実質404なのに200を返すページ」と見なされる。素直に404のほうが扱いが良い。
 *
 * ■ 使いたくなったら
 *   define('HB_AUTHOR_ARCHIVES', true);
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_author_archives_init(): void
{
    if (hb_base_const('HB_AUTHOR_ARCHIVES', false)) {
        return;
    }

    // redirect_canonical（優先度10）より前に止める。
    // 後にすると /?author=1 がログイン名入りのURLへ転送されてしまう。
    add_action('template_redirect', 'hb_author_archives_block', 1);

    // サイトマップからも外す。出口が無いのに載っていても意味がない。
    add_filter('wp_sitemaps_add_provider', 'hb_author_archives_sitemap', 10, 2);

    // SEO SIMPLE PACK に同名の設定があり、オフのままなので「効いていない」と
    // 誤解される。すでに塞いでいることをその画面に書いておく。
    add_action('admin_notices', 'hb_author_archives_ssp_notice');
}

function hb_author_archives_block(): void
{
    if (!is_author()) {
        return;
    }

    global $wp_query;

    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}

/**
 * @param mixed  $provider
 * @param string $name
 * @return mixed false を返すとその種類は出力されない
 */
function hb_author_archives_sitemap($provider, string $name)
{
    return $name === 'users' ? false : $provider;
}

/**
 * SEO SIMPLE PACK の設定画面に注記を出す。
 *
 * 向こうの「『著者』のアーカイブページを使用しない」はオフのままでよい。
 * 有効にするとトップページへの転送に変わり、こちらの404を上書きしてしまう
 * （どちらも template_redirect の優先度1なので、どちらが勝つかは読み込み順しだいになる）。
 */
function hb_author_archives_ssp_notice(): void
{
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    if (strpos($page, 'ssp-') !== 0) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="notice notice-info"><p>'
        . '<strong>「著者」のアーカイブページは「HB ベース」がすでに塞いでいます。</strong>'
        . '<code>/author/…</code> と <code>/?author=1</code> はどちらも 404 を返し、サイトマップにも載りません。<br>'
        . 'ここのトグルは<strong>オフのままで構いません</strong>。'
        . '有効にするとトップページへの転送に変わり、検索エンジンから「中身が無いのに200を返すページ」と見なされます。'
        . '</p></div>';
}
