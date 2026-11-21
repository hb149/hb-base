<?php
/**
 * 遮断画面
 *
 * WPのテーマは読み込まない（テーマ側のエラーで画面が出ないと詰むため）。
 * サイト名以外の内部情報は出さない。端末コードは申請の照合用に短縮形だけ見せる。
 *
 * @var array $context
 */

if (!defined('ABSPATH')) {
    exit;
}

$id        = (string) $context['id'];
$ip        = (string) $context['ip'];
$message   = (string) $context['message'];
$submitted = (bool) $context['submitted'];
$token     = (string) $context['token'];
$site      = (string) $context['site'];
$canRequest = (bool) $context['can_request'];
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>アクセスできません</title>
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; padding: 2rem 1rem;
    font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Noto Sans JP", sans-serif;
    line-height: 1.7; background: #f6f7f7; color: #1d2327;
  }
  @media (prefers-color-scheme: dark) { body { background: #16181a; color: #e6e7e8; } }
  .box {
    max-width: 34rem; margin: 0 auto; padding: 1.75rem;
    background: #fff; border: 1px solid #dcdcde; border-radius: 6px;
  }
  @media (prefers-color-scheme: dark) { .box { background: #1f2124; border-color: #3c434a; } }
  h1 { font-size: 1.15rem; margin: 0 0 .75rem; }
  p { margin: 0 0 1rem; font-size: .95rem; }
  dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem .75rem; margin: 0 0 1.25rem;
       font-size: .85rem; }
  dt { color: #646970; }
  dd { margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  label { display: block; font-size: .85rem; margin-bottom: .25rem; }
  input[type=text], textarea {
    width: 100%; box-sizing: border-box; padding: .5rem;
    border: 1px solid #8c8f94; border-radius: 4px; font: inherit; margin-bottom: .9rem;
    background: transparent; color: inherit;
  }
  button {
    padding: .55rem 1.2rem; border: 0; border-radius: 4px;
    background: #2271b1; color: #fff; font: inherit; cursor: pointer;
  }
  .note { font-size: .85rem; color: #646970; }
  .msg { padding: .7rem .9rem; border-radius: 4px; background: #f0f6fc;
         border-left: 4px solid #2271b1; margin-bottom: 1.25rem; font-size: .9rem; }
</style>
</head>
<body>
<div class="box">
  <h1>この端末からは管理画面を開けません</h1>

  <?php if ($message !== '') : ?>
    <p class="msg"><?php echo esc_html($message); ?></p>
  <?php endif; ?>

  <p>
    <?php echo esc_html($site); ?> の管理画面は、許可された回線と端末からのみ利用できます。
  </p>

  <dl>
    <dt>端末コード</dt><dd><?php echo esc_html(substr($id, 0, 8)); ?></dd>
    <dt>接続元IP</dt><dd><?php echo esc_html($ip); ?></dd>
  </dl>

  <?php if ($canRequest && !$submitted) : ?>
    <p class="note">この端末での利用を希望する場合は、下のフォームから管理者に申請してください。</p>
    <form method="post">
      <input type="hidden" name="hb_device_request" value="1">
      <input type="hidden" name="hb_token" value="<?php echo esc_attr($token); ?>">

      <label for="hb_name">お名前</label>
      <input type="text" id="hb_name" name="hb_name" maxlength="60" autocomplete="name">

      <label for="hb_note">用途・事情（任意）</label>
      <textarea id="hb_note" name="hb_note" rows="3" maxlength="300"></textarea>

      <button type="submit">管理者に申請する</button>
    </form>
  <?php elseif (!$canRequest) : ?>
    <p class="note">管理者に連絡し、上の端末コードを伝えてください。</p>
  <?php endif; ?>
</div>
</body>
</html>
