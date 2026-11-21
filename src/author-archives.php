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
