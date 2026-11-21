<?php
/**
 * ACF Extended の文言を日本語にする
 *
 * ACFE には同梱の翻訳がフランス語しか無く（lang/acfe-fr_FR.mo のみ）、
 * モーダルの「Close」などが英語のまま出る。
 * 公式の日本語訳が出れば不要になるので、ここでは実際に画面へ出るものだけを
 * 最小限に差し替える。翻訳ファイルを自作するより更新に強い。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 画面に出るACFEの文言。左が原文、右が置き換え後。
 * 原文が変わると効かなくなるが、その場合は英語のまま出るだけで壊れない。
 */
function hb_acfe_translations(): array
{
    return [
        'Close'          => '閉じる',
        'Cancel'         => 'キャンセル',
        'Save'           => '保存',
        'Edit'           => '編集',
        'Add Row'        => '行を追加',
        'Remove Row'     => '行を削除',
        'Expand Details' => '詳細を開く',
        'Collapse Details' => '詳細を閉じる',
        'Select'         => '選択',
        'Deselect'       => '選択を解除',
        'Toggle'         => '切り替え',
    ];
}

function hb_acfe_ja_init(): void
{
    // サイトが日本語でなければ何もしない。
    if (!in_array(get_locale(), ['ja', 'ja_JP'], true)) {
        return;
    }

    add_filter('gettext_with_context_acfe', 'hb_translate_acfe_ctx', 10, 4);
    add_filter('gettext_acfe', 'hb_translate_acfe', 10, 3);
}

function hb_translate_acfe(string $translated, string $text, string $domain): string
{
    $map = hb_acfe_translations();
    return $map[$text] ?? $translated;
}

function hb_translate_acfe_ctx(string $translated, string $text, string $context, string $domain): string
{
    $map = hb_acfe_translations();
    return $map[$text] ?? $translated;
}
