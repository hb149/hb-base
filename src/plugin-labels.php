<?php
/**
 * 他のプラグインが作る管理メニューの名前を短くする
 *
 * プラグインは自分の製品名をそのままメニューに出す。
 * 「Snow Monkey Forms」のような名前は、クライアントにとっては
 * 何の機能か分からないうえ、左メニューで2行に折り返して読みづらい。
 *
 * 投稿タイプの登録時にラベルだけ差し替える。プラグイン本体は触らないので
 * 更新で消えず、プラグイン一覧や設定画面では本来の名前のまま残る
 * （どのプラグインの話か分からなくなると保守で困るため）。
 *
 * 増やすときは下の表に足すか、案件側で:
 *   add_filter('hb_post_type_labels', function ($map) {
 *       $map['shop_order'] = '注文';
 *       return $map;
 *   });
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_plugin_labels_init(): void
{
    add_filter('register_post_type_args', 'hb_plugin_labels_post_type', 20, 2);
}

/**
 * 投稿タイプ => 表示したい名前。
 */
function hb_plugin_labels_map(): array
{
    return apply_filters('hb_post_type_labels', [
        'snow-monkey-forms' => 'フォーム',
    ]);
}

/**
 * 登録時の引数を書き換える。
 *
 * WordPress は labels が無ければ label から各種ラベルを組み立てる。
 * labels を持っているプラグインもあるので、その場合は名前の項目だけ上書きする。
 */
function hb_plugin_labels_post_type(array $args, string $post_type): array
{
    $map = hb_plugin_labels_map();

    if (!isset($map[$post_type])) {
        return $args;
    }

    $name = (string) $map[$post_type];

    $args['label'] = $name;

    if (!empty($args['labels']) && is_array($args['labels'])) {
        foreach (['name', 'singular_name', 'menu_name', 'all_items'] as $key) {
            if (isset($args['labels'][$key])) {
                $args['labels'][$key] = $name;
            }
        }
    }

    return $args;
}
