<?php
/**
 * Secure Passkeys の日本語化
 *
 * 本家に日本語の言語パックが無く（同梱はアラビア語のみ）、
 * ログイン画面のボタンもエラーも英語のまま出る。
 *
 * 翻訳ファイル(.mo/.l10n.php)を作る方法もあるが、ここでは gettext フィルタで
 * 差し替える。プラグイン本体のファイルを触らないので更新で消えず、
 * 原文が変わった箇所は英語のまま出るだけで壊れない。
 * （画面に出るJS側の文言も、元は PHP の __() なのでここで一緒に変わる）
 *
 * 本家に日本語パックが入ったら、このファイルは丸ごと不要になる。
 */

if (!defined('ABSPATH')) {
    exit;
}

function hb_passkey_ja_init(): void
{
    // 日本語以外で見ている利用者には手を出さない。
    if (!in_array(get_locale(), ['ja', 'ja_JP'], true)) {
        return;
    }

    add_filter('gettext_secure-passkeys', 'hb_passkey_ja_translate', 10, 3);
    add_filter('gettext_with_context_secure-passkeys', 'hb_passkey_ja_translate_ctx', 10, 4);
}

function hb_passkey_ja_translate(string $translated, string $text, string $domain): string
{
    $map = hb_passkey_ja_map();

    return $map[$text] ?? $translated;
}

function hb_passkey_ja_translate_ctx(string $translated, string $text, string $context, string $domain): string
{
    $map = hb_passkey_ja_map();

    return $map[$text] ?? $translated;
}

/**
 * 原文 => 日本語。
 * %1$s などの差し込みは位置を変えずに残すこと（順番を変えると値が入れ替わる）。
 */
function hb_passkey_ja_map(): array
{
    static $map = null;

    if ($map !== null) {
        return $map;
    }

    $map = [
        /* ---- ログイン画面（利用者が最初に見る） ---- */
        'Login via Passkey'   => 'パスキーでログイン',
        'Login via Passkey..' => '認証中…',
        'Your browser does not support passkeys. Try updating your browser or using another one.'
            => 'このブラウザはパスキーに対応していません。ブラウザを更新するか、別のブラウザをお試しください。',
        'Failed to fetch passkey login options.' => 'パスキーの認証情報を取得できませんでした。',
        'Passkey authentication failed. Please try again if you want to proceed.'
            => 'パスキー認証に失敗しました。もう一度お試しください。',
        'Passkey authentication cancelled. Please try again if you want to proceed.'
            => 'パスキー認証をキャンセルしました。もう一度お試しください。',
        'Login was cancelled, or no passkey is available for this device.'
            => 'キャンセルされたか、この端末に使えるパスキーがありません。',
        'You have successfully logged in with Passkey. Redirecting...'
            => 'パスキーでログインしました。画面を切り替えています…',
        'Passkey authentication failed. Please try again.' => 'パスキー認証に失敗しました。もう一度お試しください。',
        'This passkey is not active. Please sign in using your username and password, or contact your administrator for assistance.'
            => 'このパスキーは無効になっています。ユーザー名とパスワードでログインするか、管理者にお問い合わせください。',

        /* ---- パスキーの登録（プロフィール画面） ---- */
        'Passwordless sign-in with passkeys' => 'パスキーによるパスワードレスログイン',
        'Passkeys offer a secure and user-friendly authentication method, serving as an alternative or complement to traditional methods. They validate your identity through touch, facial recognition, device passwords, or PINs, and can effectively replace passwords.'
            => 'パスキーは、指紋・顔認証・端末のパスワードやPINで本人確認を行う、安全で扱いやすいログイン方法です。パスワードの代わりとして使えます。',
        'Add Passkey'   => 'パスキーを追加',
        'Add'           => '追加',
        'Cancel'        => 'キャンセル',
        'Your Passkeys' => '登録済みのパスキー',
        'out of'        => '/',
        'Waiting for input from browser interaction ...' => 'ブラウザの操作を待っています…',
        'Added'     => '登録日',
        'Last used' => '最終利用',
        'Name your security key' => 'パスキーに名前を付ける',
        "This passkey can work across multiple devices - pick a nickname that will help you identify it later.\n For example, the name of your password manager or account provider."
            => "このパスキーは複数の端末で使えることがあります。後から見分けられる名前を付けてください。\n 例: パスワード管理アプリの名前、アカウントの提供元など。",
        'Enter Security Key Name' => 'パスキーの名前を入力',
        'Inactive' => '無効',
        'Active'   => '有効',
        'An error occurred while loading passkeys.' => 'パスキーの読み込み中にエラーが発生しました。',
        'Passkey registration failed or cancelled.' => 'パスキーの登録に失敗したか、キャンセルされました。',
        'The security key name must be between 3 and 30 characters long.'
            => 'パスキーの名前は3文字以上30文字以内で入力してください。',
        "You already registered this device. You don't have to register it again."
            => 'この端末はすでに登録済みです。登録し直す必要はありません。',
        'Passkey registration cancelled. Please try again if you want to proceed.'
            => 'パスキーの登録をキャンセルしました。もう一度お試しください。',
        'Are you sure you want to delete your passkey?' => 'このパスキーを削除しますか？',
        'Passkey successfully deleted.' => 'パスキーを削除しました。',
        'Failed to fetch passkey registration options.' => 'パスキー登録用の情報を取得できませんでした。',
        'An error occurred while saving the passkey.' => 'パスキーの保存中にエラーが発生しました。',
        'Passkey successfully added.' => 'パスキーを追加しました。',
        'No passkeys found.' => '登録済みのパスキーはありません。',
        'Security key name must be at least 3 characters long.' => 'パスキーの名前は3文字以上で入力してください。',
        'Security key name must be at most 30 characters long.' => 'パスキーの名前は30文字以内で入力してください。',
        'Please use only letters, numbers, spaces, hyphens, or underscores.'
            => '英数字・スペース・ハイフン・アンダースコアのみ使用できます。',
        'The maximum number of registered passkeys has been reached.' => '登録できるパスキーの上限に達しています。',
        'This passkey is already registered. Please use a different one.' => 'このパスキーは登録済みです。別のパスキーをお使いください。',
        'Failed to register the passkey. Please try again later.' => 'パスキーを登録できませんでした。時間をおいてお試しください。',
        'You do not have permission to remove this passkey.' => 'このパスキーを削除する権限がありません。',

        /* ---- 共通の操作・エラー ---- */
        'Too many requests. Please try again later.' => '試行回数が上限に達しました。時間をおいてお試しください。',
        'You do not have permission to make this request.' => 'この操作を行う権限がありません。',
        'The request method must be POST.' => 'リクエスト方式が不正です。',
        'Token mismatch, please refresh the page.' => '認証トークンが一致しません。ページを再読み込みしてください。',
        'You are not allowed to make this request.' => 'この操作は許可されていません。',
        'An unexpected error occurred.' => '予期しないエラーが発生しました。',
        'Processing...' => '処理中…',
        'Deleting...'   => '削除中…',
        'Saving...'     => '保存中…',
        'Save Changes'  => '変更を保存',
        'Loading passkeys...' => 'パスキーを読み込んでいます…',
        'Failed to fetch passkeys.' => 'パスキーを取得できませんでした。',
        'No records found.' => '該当する記録はありません。',
        '%s ago' => '%s前',

        /* ---- 管理画面 ---- */
        'Secure Passkeys' => 'パスキー',
        // プロフィール画面の項目名。節見出し（passkey-admin.php が出す「パスキー」）と
        // 並ぶので、同じ語を繰り返さない短い名前にする。
        'Passwordless Secure Passkeys' => 'ログインに使うパスキー',
        'Settings'     => '設定',
        'Passkeys'     => 'パスキー',
        'Overview'     => '概要',
        'Activity Log' => '操作ログ',
        'User Passkeys' => 'ユーザーのパスキー',
        'Login'      => 'ログイン',
        'Register'   => '登録',
        'Remove'     => '削除',
        'Delete'     => '削除',
        'Activate'   => '有効化',
        'Deactivate' => '無効化',
        'Activated'   => '有効化しました',
        'Deactivated' => '無効化しました',
        'Are you sure you want to delete the passkey?'     => 'このパスキーを削除しますか？',
        'Are you sure you want to activate the passkey?'   => 'このパスキーを有効にしますか？',
        'Are you sure you want to deactivate the passkey?' => 'このパスキーを無効にしますか？',
        'The passkey cannot be deleted' => 'パスキーを削除できませんでした',
        'The passkey deleted successfully' => 'パスキーを削除しました',
        'Invalid procedure' => '不正な操作です',
        'A passkey cannot be updated' => 'パスキーを更新できませんでした',
        'The passkey activated successfully' => 'パスキーを有効にしました',
        'The passkey deactivated successfully' => 'パスキーを無効にしました',
        'Settings updated successfully.' => '設定を保存しました。',

        'Security Key Name' => 'パスキー名',
        'Authenticator'     => '認証器',
        'Last Used'         => '最終利用',
        'Last Used Date'    => '最終利用日',
        'Created Date'      => '登録日',
        'Actions'           => '操作',
        'Status'            => '状態',
        'User'              => 'ユーザー',
        'User ID'           => 'ユーザーID',
        'User ID, Name, or Email' => 'ユーザーID・名前・メールアドレス',
        'Action'    => '操作',
        'Action By' => '操作者',
        'Any'       => 'すべて',
        'Admin'     => '管理者',
        'IP Address' => 'IPアドレス',
        'Filter'    => '絞り込み',
        'Reset'     => '条件をクリア',
        'Count'     => '件数',
        'Description' => '内容',
        'records'   => '件',
        'Page'      => 'ページ',
        'of'        => '/',
        'Previous page' => '前のページ',
        'Statistics' => '統計',
        'Users'      => 'ユーザー',
        'Activities' => '操作履歴',
        'Challenges' => 'チャレンジ',
        'Overview of Authenticators' => '認証器の内訳',
        'No authenticators registered.' => '登録された認証器はありません。',
        'Last Login Activity' => '直近のログイン',
        'No activities.' => '記録はありません。',
        'See the list of passkeys that users have created.' => 'ユーザーが登録したパスキーの一覧です。',

        /* ---- 管理画面のお知らせ ---- */
        'Simplify your sign-in.' => 'ログインを簡単にしませんか。',
        'Enable passkeys' => 'パスキーを設定する',
        '%1$s Use your fingerprint or other biometric methods with passkeys to securely and conveniently verify your login. %2$s in your profile settings.'
            => '%1$s 指紋や顔認証などのパスキーを使うと、安全かつ簡単にログインできます。プロフィール設定から %2$s。',

        /* ---- 操作ログの文面 ---- */
        'User logged in using the passkey (%1$s) with the authenticator (%2$s).'
            => 'パスキー（%1$s / 認証器: %2$s）でログインしました。',
        'Passkey registered successfully with the name (%1$s) using the authenticator (%2$s).'
            => 'パスキー「%1$s」（認証器: %2$s）を登録しました。',
        'Passkey (%1$s) associated with the authenticator (%2$s) was removed.'
            => 'パスキー「%1$s」（認証器: %2$s）を削除しました。',
        'Passkey (%1$s) associated with the authenticator (%2$s) was deleted by an administrator.'
            => 'パスキー「%1$s」（認証器: %2$s）が管理者によって削除されました。',
        'Passkey (%1$s) associated with the authenticator (%2$s) was activated by an administrator.'
            => 'パスキー「%1$s」（認証器: %2$s）が管理者によって有効化されました。',
        'Passkey (%1$s) associated with the authenticator (%2$s) was deactivated by an administrator.'
            => 'パスキー「%1$s」（認証器: %2$s）が管理者によって無効化されました。',

        /* ---- 設定画面 ---- */
        'General Settings'  => '基本設定',
        'Display Settings'  => '表示設定',
        'Advanced Settings' => '詳細設定',
        'Frontend Options'  => 'フロント側の設定',
        'Adminarea Options' => '管理画面の設定',
        'Theme' => 'テーマ',
        'Default' => '標準',
        'YOOtheme (UiKit)' => 'YOOtheme (UiKit)',
        'Never' => '削除しない',
        'Older than' => '経過日数',
        'days' => '日',
        'Required'    => '必須',
        'Preferred'   => '可能なら要求',
        'Discouraged' => '要求しない',
        '#' => '#',

        'Configure the Passkey login options to be displayed on the frontend for default WordPress and third-party plugins.'
            => 'フロント側に表示するパスキーログインの設定です。',
        'WordPress Login Form' => 'WordPressのログインフォーム',
        'Enable Passkey Login for default WordPress login.' => 'WordPress標準のログイン画面にパスキーログインを表示します。',
        'These options will work only if the respective plugins are installed and activated:'
            => '以下は該当プラグインが有効な場合のみ動作します:',
        'Login for WooCommerce' => 'WooCommerce のログイン',
        'Enable Passkey Login for WooCommerce login page.' => 'WooCommerce のログインページにパスキーログインを表示します。',
        'The %s plugin must be installed and activated to work correctly.' => '動作には %s プラグインの有効化が必要です。',
        'Login for MemberPress' => 'MemberPress のログイン',
        'Enable Passkey Login for MemberPress login form.' => 'MemberPress のログインフォームにパスキーログインを表示します。',
        'Login for Easy Digital Downloads' => 'Easy Digital Downloads のログイン',
        'Enable Passkey Login for Easy Digital Downloads login form.' => 'Easy Digital Downloads のログインフォームにパスキーログインを表示します。',
        'Login for Ultimate Member' => 'Ultimate Member のログイン',
        'Enable Passkey Login for Ultimate Member login form.' => 'Ultimate Member のログインフォームにパスキーログインを表示します。',
        'Choose a frontend theme. All themes, except "default", must be activated to function properly.'
            => 'フロント側の見た目を選びます。「標準」以外は対応テーマの有効化が必要です。',
        'You can add custom themes using a plugin filters.' => '独自テーマはフィルタで追加できます。',
        'Display Passkeys in Users List' => 'ユーザー一覧にパスキー列を表示',
        'Enable this option to show the passkeys as a column in the users list in the adminarea.'
            => '管理画面のユーザー一覧にパスキーの列を追加します。',
        'Display Passkeys in Edit User/Profile' => 'プロフィール画面にパスキーを表示',
        'Enable this option to show the passkeys on the Edit User and Profile page in the adminarea.'
            => 'ユーザー編集・プロフィール画面にパスキーの設定欄を表示します。',
        'Configure the options available in the admin area.' => '管理画面側の表示設定です。',
        'Configure the Passkey for each user.' => 'ユーザーごとのパスキーに関する設定です。',
        'Maximum Passkeys per User' => '1ユーザーあたりの上限',
        'Enable or disable the restriction for the maximum number of passkeys per user.'
            => '1ユーザーが登録できるパスキー数に上限を設けるかどうか。',
        'If disabled, users can register an unlimited number of passkeys.' => '無効にすると無制限に登録できます。',
        'Number of Passkeys' => '登録できる数',
        'Set the maximum number of passkeys a user can register (if enabled).'
            => '上限を有効にした場合の、登録できるパスキーの数。',
        'Excluded User Roles' => '対象外の権限グループ',
        'Select user roles to exclude from passkey registration and login.'
            => 'パスキーの登録・ログインを使わせない権限グループを選びます。',
        'Users with these roles will not be able to log in, and the passkey registration form will not appear on the profile or registration shortcode.'
            => '選んだ権限グループではパスキーログインができず、登録フォームも表示されません。',
        'Auto Generate Security Key Name' => 'パスキー名を自動で付ける',
        'Enable or disable the automatic generation of a security key name.' => 'パスキーの名前を自動生成するかどうか。',
        'If enabled, users will not be prompted to enter a security key name manually when registering a passkey.'
            => '有効にすると、登録時に名前の入力を求めません。',
        'Show Enable Passkeys Notice' => 'パスキー設定の案内を表示',
        'Enable or disable showing the passkeys reminder in the WordPress admin area (wp-admin) for users who have not yet enabled passkeys.'
            => 'パスキー未設定のユーザーに、管理画面で案内を表示するかどうか。',
        'If disabled, the notice will not appear even if the user has not enabled passkeys.'
            => '無効にすると、未設定でも案内は表示されません。',
        'Configure rate limiting for login attempts. This is useful for preventing brute-force attacks.'
            => 'ログイン試行の回数制限です。総当たり攻撃の対策になります。',
        'Maximum Attempts' => '最大試行回数',
        'Set the maximum number of failed login attempts allowed before blocking.'
            => '遮断するまでに許す失敗回数。',
        'Block Window (Minutes)' => '遮断時間（分）',
        'Set the number of minutes the user will be blocked after reaching the maximum attempts.'
            => '上限に達したあと遮断する時間（分）。',
        'Configure session settings for passkey logins.' => 'パスキーでログインしたときのセッション設定です。',
        'Long-lived Session' => 'ログイン状態の保持',
        'Enable Long-lived Session' => 'ログイン状態を長く保持する',
        'When enabled, passkey logins set a long-lived session cookie (same as "Remember Me"). When disabled, the session ends when the browser is closed.'
            => '有効にすると「ログイン状態を保存する」と同じ扱いになります。無効ならブラウザを閉じた時点で切れます。',
        'Configure the WebAuthn and Passkeys login and registration options., we recommend using the default settings unless you have a good understanding of WebAuthn configuration.'
            => 'WebAuthn の詳細設定です。内容を理解している場合を除き、初期値のままを推奨します。',
        'Exclude Existing Credentials' => '登録済みパスキーの重複を防ぐ',
        'Enable this option to prevent users from registering passkeys that are already registered (Recommended: enabled).'
            => '同じパスキーの二重登録を防ぎます（推奨: 有効）。',
        'Registration Timeout' => '登録の制限時間',
        'Set the expiration timeout for passkey registration (in minutes).' => 'パスキー登録の有効時間（分）。',
        'Login Timeout' => 'ログインの制限時間',
        'Set the expiration timeout for passkey login (in minutes).' => 'パスキーログインの有効時間（分）。',
        'Enable User Verifications' => '本人確認を要求する',
        'Choose the level of user verification required during passkey registration (Recommended: enabled).'
            => '登録時に求める本人確認の強さ（推奨: 有効）。',
        'Login User Verification' => 'ログイン時の本人確認',
        'Choose the level of user verification required during passkey login.'
            => 'ログイン時に求める本人確認の強さ。',
        'Manage advanced options for plugin maintenance.' => 'データの保守に関する設定です。',
        'Delete passkey challenge records older than' => 'チャレンジ記録の保持期間',
        'Automatically removes outdated passkey challenge records to optimize database performance. If set to "Never", records will not be deleted.'
            => '古いチャレンジ記録を自動削除してDBを軽く保ちます。「削除しない」にすると残り続けます。',
        'Delete log records older than' => '操作ログの保持期間',
        '(Not recommended) Automatically deletes outdated log records. If set to "Never", logs will not be removed.'
            => '（非推奨）古い操作ログを自動削除します。「削除しない」にすると残り続けます。',
        'This results in the loss of activity log history.' => '過去の操作履歴が失われます。',
        'Disable Activity Log Recording' => '操作ログの記録を停止',
        'Disable the creation of new activity log records.' => '新しい操作ログを記録しなくなります。',
        'This will prevent new activity log records from being created.' => '以後、操作ログは残りません。',
        'Configuring Cron Jobs' => 'cron の設定',
        'Log in to your web server control panel, such as cPanel or Plesk.' => 'サーバーの管理画面（cPanel や Plesk など）にログインします。',
        'Find the option to manage cron jobs and select it.' => 'cron（定期実行）の設定項目を開きます。',
        'Save the cron job.' => 'cron を保存します。',
        'Add the following code to your "wp-config.php" file:' => '次のコードを wp-config.php に追記します:',
        'Use the following shortcodes to embed the passkey login and registration forms on your frontend pages. These shortcodes are typically only necessary if you need to manually embed the forms on custom pages. By default, the login form handles various options automatically, and you may not need to manage it unless you\'re working with a custom page.'
            => 'フロントのページにパスキーのログイン／登録フォームを埋め込むためのショートコードです。独自ページに手動で置きたい場合にだけ使います。通常のログイン画面では自動で表示されるため、設定は不要です。',
        'Login Short Code' => 'ログイン用ショートコード',
        'This shortcode to embed the "Login via Passkey" login button,this allows users to log in using their passkey credentials.'
            => '「パスキーでログイン」ボタンを埋め込みます。',
        'If the user is already logged in, the form will not be displayed.' => 'ログイン済みの場合は表示されません。',
        'Register Short Code' => '登録用ショートコード',
        'This shortcode to embed the passkey registration form. This allows logged-in users to create and manage their passkey credentials.'
            => 'パスキーの登録フォームを埋め込みます。ログイン済みのユーザーが自分のパスキーを管理できます。',
        'If the user is not logged in, the form will not be displayed.' => '未ログインの場合は表示されません。',
        'View a detailed log of passkeys created by users, including timestamps and relevant activity history for better security monitoring.'
            => 'ユーザーのパスキー操作の履歴です。日時や内容を確認できます。',
        'Secure Passkeys is a passwordless authentication solution based on WebAuthn, enabling users to log in using device-based methods like biometrics or PIN. It eliminates the need for traditional passwords, enhancing security and upgrading your WordPress site with Secure Passkeys for a modern, secure, and efficient authentication experience.'
            => 'WebAuthn をもとにしたパスワードレス認証です。生体認証やPINなど端末側の方法でログインでき、パスワードが不要になります。',

        /* ---- 設定画面の検証エラー ---- */
        'The challenge cleanup period is not correct.' => 'チャレンジ記録の保持期間が不正です。',
        'The log cleanup period is not correct.' => '操作ログの保持期間が不正です。',
        'The theme does not exist.' => '指定のテーマが存在しません。',
        'The path associated with the theme does not exist.' => 'テーマのパスが存在しません。',
        'Invalid settings.' => '設定内容が不正です。',
        'The maximum number of passkeys per user must be greater than 0.' => '1ユーザーあたりの上限は1以上にしてください。',
        'The maximum number of passkeys must be less than 10.' => '1ユーザーあたりの上限は10未満にしてください。',
        'The excluded roles must be a valid role.' => '対象外の権限グループの指定が不正です。',
        'The login user verification must be required, preferred, or discouraged.'
            => 'ログイン時の本人確認の指定が不正です。',
        'The registration timeout must be greater than 0.' => '登録の制限時間は1以上にしてください。',
        'The rate limiting max attempts must be greater than 0.' => '最大試行回数は1以上にしてください。',
        'The rate limiting window must be greater than 0 minutes.' => '遮断時間は1分以上にしてください。',
        'The login timeout must be greater than 0.' => 'ログインの制限時間は1以上にしてください。',
    ];

    // 原文に曲がり引用符（’ “ ”）が入っているもの。コピー間違いを避けるため分けて置く。
    $map['To ensure these options work as expected, it is recommended to set up a cron job on your server. A cron job is a scheduled task that automatically runs at specified intervals. Here’s how you can set up a cron job:']
        = 'これらの設定を確実に動かすには、サーバー側で cron（定期実行）を設定することをおすすめします。手順は次のとおりです:';
    $map['In the “Add New Cron Job” section, specify the frequency at which you want the cron job to run, for example, every hour or every day.']
        = '「新しい cron を追加」で、実行する間隔（1時間ごと、1日ごとなど）を指定します。';
    $map['In the “Command” field, enter the following command:']
        = '「コマンド」欄に次の内容を入力します:';

    return $map;
}
