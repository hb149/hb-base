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
                            ['page' => 'hb-support', 'ticket' => (string) $item['id']],
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
