<?php
/**
 * サポートの詳細（やり取り＋返信）
 *
 * ボタンは features と allowed_actions の両方で絞る。
 * サイト全体で閉じているのと、この1件で今は押せないのは別の話。
 *
 * @var array $data      GET /api/wp/tickets/{id} の応答
 * @var array $features  ping の features
 * @var string $id
 */

if (!defined('ABSPATH')) {
    exit;
}

$messages = is_array($data['messages']['items'] ?? null) ? $data['messages']['items'] : [];
$allowed  = is_array($data['allowed_actions'] ?? null) ? $data['allowed_actions'] : [];
$quota    = is_array($data['quota'] ?? null) ? $data['quota'] : [];

if (empty($features['actions'])) {
    $allowed = [];
}
?>

<h1><?php echo esc_html((string) ($data['title'] ?? '')); ?></h1>
<p><a href="<?php echo esc_url(admin_url('admin.php?page=hb-support')); ?>">&larr; 一覧へ戻る</a></p>

<table class="form-table" role="presentation">
    <tbody>
        <tr>
            <th scope="row">状況</th>
            <td><?php echo esc_html((string) ($data['status_label'] ?? '')); ?></td>
        </tr>
        <tr>
            <th scope="row">担当</th>
            <td><?php echo esc_html((string) ($data['assignee_name'] ?? '—')); ?></td>
        </tr>
        <tr>
            <th scope="row">受付</th>
            <td><?php echo esc_html(hb_support_time($data['received_at'] ?? null)); ?></td>
        </tr>
        <?php if (!empty($data['spot_amount'])) : ?>
        <tr>
            <th scope="row">見積</th>
            <td><?php echo esc_html(number_format((float) $data['spot_amount'])); ?>円</td>
        </tr>
        <?php endif; ?>
        <?php if (!empty($quota)) : ?>
        <tr>
            <th scope="row">今月の依頼</th>
            <td>
                <?php printf(
                    '%d件 / %d件（残り %d件）',
                    (int) ($quota['used'] ?? 0),
                    (int) ($quota['limit'] ?? 0),
                    (int) ($quota['remaining'] ?? 0)
                ); ?>
            </td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<h2>やり取り</h2>

<table class="wp-list-table widefat striped">
    <tbody>
    <?php foreach ($messages as $message) :
        $author = is_array($message['author'] ?? null) ? $message['author'] : [];
        $isStaff = ($author['type'] ?? '') === 'staff';
        ?>
        <tr>
            <td style="width:14rem">
                <strong><?php echo esc_html((string) ($author['name'] ?? '')); ?></strong>
                <br><span class="description"><?php echo $isStaff ? '保守担当' : 'お客様'; ?></span>
                <br><span class="description"><?php echo esc_html(hb_support_time($message['at'] ?? null)); ?></span>
            </td>
            <td>
                <?php echo hb_support_text((string) ($message['body'] ?? '')); // 本文は平文。esc_html 済み ?>
                <?php if (!empty($message['attachments']) && !empty($features['attachments'])) : ?>
                    <p class="description">
                        <?php foreach ($message['attachments'] as $file) : ?>
                            <span class="dashicons dashicons-paperclip"></span>
                            <?php echo esc_html((string) ($file['filename'] ?? '')); ?>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($allowed)) : ?>
    <h2>この依頼でできること</h2>
    <form method="post">
        <?php wp_nonce_field('hb_support'); ?>
        <input type="hidden" name="hb_ticket" value="<?php echo esc_attr($id); ?>">
        <p>
            <label for="hb_comment">ひとこと（任意）</label><br>
            <textarea id="hb_comment" name="hb_comment" rows="2" class="large-text"></textarea>
        </p>
        <p class="submit">
            <?php foreach ($allowed as $action) : ?>
                <button type="submit" name="hb_support_action" value="<?php echo esc_attr($action); ?>"
                        class="button<?php echo $action === 'withdraw' ? '' : ' button-primary'; ?>">
                    <?php echo esc_html(hb_support_action_label((string) $action)); ?>
                </button>
            <?php endforeach; ?>
        </p>
    </form>
<?php endif; ?>

<h2>返信する</h2>
<form method="post">
    <?php wp_nonce_field('hb_support'); ?>
    <input type="hidden" name="hb_ticket" value="<?php echo esc_attr($id); ?>">
    <p>
        <textarea name="hb_body" rows="5" class="large-text" maxlength="10000"
                  placeholder="ご用件をご記入ください。"></textarea>
    </p>
    <p class="submit">
        <button type="submit" name="hb_support_action" value="reply" class="button button-primary">送信する</button>
    </p>
</form>
