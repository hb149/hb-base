<?php
/**
 * サポートの一覧
 *
 * 並び順はアプリ側の対応順。こちらで並べ替えない（ページをまたぐと崩れる）。
 *
 * @var array $data  GET /api/wp/tickets の応答
 * @var string $state
 */

if (!defined('ABSPATH')) {
    exit;
}

$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$tabs  = ['open' => '対応中', 'closed' => '完了・取消', 'all' => 'すべて'];
?>

<h2 class="nav-tab-wrapper">
    <?php foreach ($tabs as $key => $label) : ?>
        <a href="<?php echo esc_url(add_query_arg(['page' => 'hb-support', 'state' => $key], admin_url('admin.php'))); ?>"
           class="nav-tab<?php echo $key === $state ? ' nav-tab-active' : ''; ?>">
            <?php echo esc_html($label); ?>
        </a>
    <?php endforeach; ?>
</h2>

<?php if (empty($items)) : ?>
    <p><?php echo $state === 'open' ? '対応中の依頼はありません。' : '該当する依頼はありません。'; ?></p>
<?php else : ?>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th scope="col">件名</th>
                <th scope="col">状況</th>
                <th scope="col">種別</th>
                <th scope="col">担当</th>
                <th scope="col">最終やり取り</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item) :
            $unread = (int) ($item['unread_count'] ?? 0);
            ?>
            <tr>
                <td>
                    <strong>
                        <a href="<?php echo esc_url(add_query_arg(
                            array_filter([
                                'page'   => 'hb-support',
                                'ticket' => (string) $item['id'],
                                // 既読にしてよいかの手がかり（詳細の応答に未読数が無いため）
                                'unread' => $unread > 0 ? '1' : null,
                            ]),
                            admin_url('admin.php')
                        )); ?>"><?php echo esc_html((string) ($item['title'] ?? '')); ?></a>
                    </strong>
                    <?php if ($unread > 0) : ?>
                        <span class="awaiting-mod"><span class="pending-count"><?php echo (int) $unread; ?></span></span>
                    <?php endif; ?>
                    <?php if (!empty($item['spot_amount'])) : ?>
                        <br><span class="description">見積 <?php echo esc_html(number_format((float) $item['spot_amount'])); ?>円</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html((string) ($item['status_label'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($item['kind_label'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($item['assignee_name'] ?? '—')); ?></td>
                <td><?php echo esc_html(hb_support_time($item['last_message_at'] ?? null)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($features['create'])) : ?>
    <h2>新しく依頼する</h2>
    <form method="post">
        <?php wp_nonce_field('hb_support'); ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="hb_title">件名</label></th>
                    <td><input type="text" id="hb_title" name="hb_title" class="regular-text"
                               maxlength="120" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hb_kind">種別</label></th>
                    <td>
                        <select id="hb_kind" name="hb_kind">
                            <?php foreach (hb_support_kinds() as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hb_new_body">内容</label></th>
                    <td>
                        <textarea id="hb_new_body" name="hb_body" rows="5" class="large-text"
                                  maxlength="10000" required></textarea>
                        <?php if (isset($data['quota']['remaining']) && (int) $data['quota']['remaining'] === 0) : ?>
                            <p class="description">
                                今月の件数を使い切っています。別途お見積りになる場合があります。
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <button type="submit" name="hb_support_action" value="create" class="button button-primary">依頼する</button>
        </p>
    </form>
<?php endif; ?>
