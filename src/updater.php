<?php
/**
 * GitHub Releases からの自動更新
 *
 * WordPress 5.8 以降は、プラグインヘッダに「Update URI:」があると
 * そのホスト名のフィルタ（update_plugins_github.com）を叩いてくれる。
 * つまり外部ライブラリを足さずに、管理画面の「更新」にそのまま乗せられる。
 *
 * これで各サイトは、
 *   - プラグイン一覧に「更新があります」が出る
 *   - 「自動更新を有効化」を1度押せば、以後は cron が勝手に上げる
 * ようになる。リリース側はタグを打って Release を出すだけ。
 *
 * このファイルは他の自作プラグインからもそのまま使える。
 * 各プラグインの本体で hb_github_updater_register() を1回呼ぶ。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 更新対象として登録する。
 *
 * @param array{file:string,slug:string,repo:string,version:string,name?:string,tested?:string,requires_php?:string} $args
 */
function hb_github_updater_register(array $args): void
{
    static $hooked = false;

    $registry = &hb_github_updater_registry();

    $registry[plugin_basename($args['file'])] = $args;

    if ($hooked) {
        return;
    }

    $hooked = true;

    add_filter('update_plugins_github.com', 'hb_github_updater_check', 10, 3);
    add_filter('upgrader_source_selection', 'hb_github_updater_fix_dirname', 10, 4);
    add_filter('plugins_api', 'hb_github_updater_details', 10, 3);
}

function &hb_github_updater_registry(): array
{
    static $registry = [];

    return $registry;
}

function hb_github_updater_find(string $pluginFile): ?array
{
    $registry = hb_github_updater_registry();

    return $registry[$pluginFile] ?? null;
}

function hb_github_updater_find_by_slug(string $slug): ?array
{
    foreach (hb_github_updater_registry() as $args) {
        if ($args['slug'] === $slug) {
            return $args;
        }
    }

    return null;
}

/* ---------------------------------------------------------------------------
 * 更新の有無
 * -------------------------------------------------------------------------*/

/**
 * @param array|false $update
 * @return array|false
 */
function hb_github_updater_check($update, array $plugin_data, string $plugin_file)
{
    $args = hb_github_updater_find($plugin_file);

    if ($args === null) {
        return $update;
    }

    $release = hb_github_updater_latest_release($args['repo']);

    if ($release === null) {
        return $update;
    }

    return [
        'slug'         => $args['slug'],
        'version'      => $release['version'],
        'url'          => 'https://github.com/' . $args['repo'],
        'package'      => $release['package'],
        'tested'       => $args['tested'] ?? '',
        'requires_php' => $args['requires_php'] ?? '',
    ];
}

/**
 * 最新リリースを取る。GitHubの未認証APIは1時間60回までなので必ずキャッシュする。
 *
 * @return array{version:string,package:string,body:string,published:string}|null
 */
function hb_github_updater_latest_release(string $repo): ?array
{
    $key = 'hb_gh_rel_' . md5($repo);

    // 管理画面で「更新を確認」を押したときは取り直す。
    if (!empty($_GET['force-check'])) {
        delete_transient($key);
    }

    $cached = get_transient($key);

    if (is_array($cached)) {
        return $cached['version'] === '' ? null : $cached;
    }

    $response = wp_remote_get('https://api.github.com/repos/' . $repo . '/releases/latest', [
        'timeout' => 10,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        // 失敗を短く覚えて、落ちている相手を叩き続けないようにする。
        set_transient($key, ['version' => '', 'package' => '', 'body' => '', 'published' => ''], 15 * MINUTE_IN_SECONDS);

        return null;
    }

    $body = json_decode((string) wp_remote_retrieve_body($response), true);

    if (!is_array($body) || empty($body['tag_name'])) {
        return null;
    }

    $slug = substr($repo, strpos($repo, '/') + 1);

    $release = [
        'version'   => ltrim((string) $body['tag_name'], 'vV'),
        'package'   => hb_github_updater_package_url($body, $slug),
        'body'      => (string) ($body['body'] ?? ''),
        'published' => (string) ($body['published_at'] ?? ''),
    ];

    set_transient($key, $release, 6 * HOUR_IN_SECONDS);

    return $release;
}

/**
 * ダウンロードするzipを選ぶ。
 *
 * リリースに <slug>.zip を添付していればそれを使う。
 * 添付が無ければGitHubが自動生成するソースzipを使うが、
 * 中のフォルダ名が「repo-1.0.1」になるので別途詰め直しが要る（下の関数で処理）。
 */
function hb_github_updater_package_url(array $release, string $slug): string
{
    foreach ((array) ($release['assets'] ?? []) as $asset) {
        if (($asset['name'] ?? '') === $slug . '.zip' && !empty($asset['browser_download_url'])) {
            return (string) $asset['browser_download_url'];
        }
    }

    return (string) ($release['zipball_url'] ?? '');
}

/* ---------------------------------------------------------------------------
 * 展開時のフォルダ名
 * -------------------------------------------------------------------------*/

/**
 * 展開されたフォルダ名をプラグインのスラッグに合わせる。
 *
 * GitHubのソースzipは「owner-repo-abc1234」という名前で展開されるため、
 * そのまま入れるとプラグインが別物として増殖し、有効化も外れる。
 *
 * @param string|WP_Error $source
 * @return string|WP_Error
 */
function hb_github_updater_fix_dirname($source, $remote_source, $upgrader, $hook_extra = null)
{
    if (is_wp_error($source) || !is_array($hook_extra) || empty($hook_extra['plugin'])) {
        return $source;
    }

    $args = hb_github_updater_find((string) $hook_extra['plugin']);

    if ($args === null) {
        return $source;
    }

    $desired = trailingslashit($remote_source) . $args['slug'];

    if (untrailingslashit($source) === $desired) {
        return $source;
    }

    global $wp_filesystem;

    if (!$wp_filesystem || !$wp_filesystem->move($source, $desired, true)) {
        return new WP_Error(
            'hb_rename_failed',
            'ダウンロードしたファイルのフォルダ名を変更できませんでした。'
        );
    }

    return trailingslashit($desired);
}

/* ---------------------------------------------------------------------------
 * 「詳細を表示」
 * -------------------------------------------------------------------------*/

/**
 * wordpress.org に無いプラグインなので、詳細画面の中身を自分で返す。
 *
 * @param false|object|array $result
 * @return false|object|array
 */
function hb_github_updater_details($result, string $action, $args)
{
    if ($action !== 'plugin_information' || empty($args->slug)) {
        return $result;
    }

    $plugin = hb_github_updater_find_by_slug((string) $args->slug);

    if ($plugin === null) {
        return $result;
    }

    $release = hb_github_updater_latest_release($plugin['repo']);

    return (object) [
        'name'          => $plugin['name'] ?? $plugin['slug'],
        'slug'          => $plugin['slug'],
        'version'       => $release['version'] ?? $plugin['version'],
        'requires_php'  => $plugin['requires_php'] ?? '',
        'tested'        => $plugin['tested'] ?? '',
        'homepage'      => 'https://github.com/' . $plugin['repo'],
        'download_link' => $release['package'] ?? '',
        'last_updated'  => $release['published'] ?? '',
        'sections'      => [
            'description' => '社内共有プラグイン。配布元: <a href="https://github.com/' . esc_attr($plugin['repo']) . '">'
                . esc_html($plugin['repo']) . '</a>',
            'changelog'   => $release !== null && $release['body'] !== ''
                ? wpautop(esc_html($release['body']))
                : '<p>変更履歴はGitHubのリリースを参照してください。</p>',
        ],
    ];
}
