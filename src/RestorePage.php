<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** The one restore screen shared by the WordPress plugin and the independent entry. It holds no secrets; assets/restore.js drives it. */
final class RestorePage
{
    /** @param array{endpoint:string,tokenName:string,token:string,extra?:array<string,string>,reauth:bool,prefix:string} $page */
    public static function render(array $page): string
    {
        $e = static function (string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $field = static function (string $type, string $name, string $label, string $help = '', string $attributes = '', string $value = '') use ($e): string {
            $secret = $type === 'password';
            return '<p class="wrb-r-field"><label for="wrb-r-' . $name . '">' . $e($label) . '</label>' . ($help !== '' ? '<span id="wrb-r-' . $name . '-help" class="wrb-r-help">' . $e($help) . '</span>' : '')
                . '<input id="wrb-r-' . $name . '" name="' . $name . '" type="' . $type . '"' . ($secret ? ' data-secret autocomplete="new-password"' : ' value="' . $e($value) . '" autocomplete="off"') . ' autocapitalize="off" spellcheck="false"' . ($help !== '' ? ' aria-describedby="wrb-r-' . $name . '-help"' : '') . ' ' . $attributes . '><span class="wrb-r-error" data-error-for="' . $name . '" role="alert"></span></p>';
        };
        $text = static function (string $name, string $label, string $help = '', string $attributes = '', string $value = '') use ($field): string { return $field('text', $name, $label, $help, $attributes, $value); };
        $secret = static function (string $name, string $label, string $help = '', string $attributes = '') use ($field): string { return $field('password', $name, $label, $help, $attributes); };
        $extra = '';
        foreach ($page['extra'] ?? [] as $name => $value) { $extra .= ' data-extra-' . $e($name) . '="' . $e($value) . '"'; }
        $html = '<div class="wrb-restore" id="wrb-restore" data-endpoint="' . $e($page['endpoint']) . '" data-token-name="' . $e($page['tokenName']) . '" data-token="' . $e($page['token']) . '"' . $extra . '>';
        $html .= '<noscript><p class="wrb-r-note wrb-r-bad">復元画面の操作にはJavaScriptが必要です。</p></noscript>';
        $html .= '<p class="wrb-r-scope" data-scope>' . $e(RestoreService::SCOPE) . '</p>';
        $html .= '<div class="wrb-r-layout"><form id="wrb-r-form" class="wrb-r-main" novalidate>';

        $html .= '<section class="wrb-r-step" aria-labelledby="wrb-r-h-source"><h2 id="wrb-r-h-source"><span aria-hidden="true">1</span>復元するバックアップ</h2>';
        $html .= '<p class="wrb-r-field"><label for="wrb-r-snapshot">バックアップ</label><span id="wrb-r-snapshot-help" class="wrb-r-help">取得日時は、下の秘密鍵を入れて検査すると表示されます。</span><select id="wrb-r-snapshot" name="snapshot" aria-describedby="wrb-r-snapshot-help"><option value="">読み込み中…</option></select><span class="wrb-r-error" data-error-for="snapshot" role="alert"></span></p>';
        $html .= '<div class="wrb-r-field"><label for="wrb-r-identity">秘密鍵（age）</label><p id="wrb-r-identity-help" class="wrb-r-help">バックアップの設定時に作り、サイトの外に保管した鍵ファイルです。ファイルを選ぶか、<code>AGE-SECRET-KEY-1</code>で始まる行を貼り付けてください（<code>#</code>で始まる行は含んでいてもかまいません）。<code>age1</code>で始まる公開鍵ではありません。鍵は保存せず、開始後に画面からも消します。</p>';
        $html .= '<textarea id="wrb-r-identity" name="identity" rows="2" data-secret autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="wrb-r-identity-help"></textarea><span class="wrb-r-error" data-error-for="identity" role="alert"></span><label class="wrb-r-file">鍵ファイルを選ぶ <input type="file" data-key-file></label></div></section>';

        $html .= '<section class="wrb-r-step" aria-labelledby="wrb-r-h-db"><h2 id="wrb-r-h-db"><span aria-hidden="true">2</span>データベース</h2>';
        $html .= '<fieldset class="wrb-r-choice"><legend class="wrb-r-sr">データベースの種類</legend><label><input type="radio" name="database" value="sqlite" checked> <strong>かんたん（SQLite）</strong><span class="wrb-r-help">おすすめ。データベースサーバーは不要で、入力もありません。</span></label><label><input type="radio" name="database" value="mysql"> <strong>データベースサーバー（MySQL／MariaDB）</strong><span class="wrb-r-help">管理者が準備した専用サーバーの、空のデータベースへ復元します。</span></label></fieldset>';
        $html .= '<div data-show-when="database=mysql" hidden><p class="wrb-r-field"><label for="wrb-r-connection">接続先</label><select id="wrb-r-connection" name="connection"></select><span class="wrb-r-error" data-error-for="connection" role="alert"></span></p><p class="wrb-r-note" data-connection-detail></p><p class="wrb-r-note wrb-r-warn" data-connection-empty hidden>接続先がまだ登録されていません。管理者が下の準備手順で登録すると選べます。</p>';
        $html .= '<details data-manual-accounts><summary>データベース名とアカウントを入力</summary>' . $text('mysqlDatabase', 'データベース名', '確認用のコピーは「この名前_run」へ作ります。', 'maxlength="60" placeholder="wrb_test_example"');
        foreach (['inspector' => '確認用', 'importer' => '取り込み用', 'runner' => 'サイト用'] as $role => $label) { $html .= '<div class="wrb-r-pair">' . $text($role . 'User', $label . 'アカウント', '', 'data-secret') . $secret($role . 'Password', $label . 'パスワード') . '</div>'; }
        $html .= '</details><details><summary>準備手順（管理者向け）</summary><ol class="wrb-r-steps"><li>専用のデータベースサーバーを <code>--event-scheduler=DISABLED</code> で起動し、作業フォルダー内のソケットだけで待ち受けます。業務のデータベースと同じサーバーは使わないでください。</li><li>作業フォルダーに <code>.wrb-synthetic-mysql</code> の印を置きます。</li><li>空のデータベースを2つ（元データ用と <code>_run</code>）と、専用アカウントを作ります。元データとサイトは別のアカウントで分けます。</li></ol><pre><code>CREATE DATABASE wrb_test_example CHARACTER SET utf8mb4;
CREATE DATABASE wrb_test_example_run CHARACTER SET utf8mb4;
CREATE USER \'wrb_import\'@\'localhost\' IDENTIFIED BY \'&lt;取込用パスワード&gt;\';
CREATE USER \'wrb_run\'@\'localhost\' IDENTIFIED BY \'&lt;起動用パスワード&gt;\';
GRANT ALTER, CREATE, DELETE, DROP, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT, UPDATE
  ON `wrb\_test\_example`.* TO \'wrb_import\'@\'localhost\';
GRANT ALTER, CREATE, CREATE TEMPORARY TABLES, DELETE, DROP, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT, UPDATE
  ON `wrb\_test\_example\_run`.* TO \'wrb_run\'@\'localhost\';</code></pre><p class="wrb-r-help">MySQLの <code>partial_revokes=ON</code> では下線をエスケープしません。接続先は0600のJSONで登録します（手順は独立復旧のREADME）。</p></details></div></section>';

        $html .= '<section class="wrb-r-step" aria-labelledby="wrb-r-h-url"><h2 id="wrb-r-h-url"><span aria-hidden="true">3</span>新しいサイト</h2>';
        $html .= $text('targetUrl', '新しいアドレス（ドメインを変えるときだけ）', 'サイトの設定をこのアドレスへ書き換えます。ここへは接続せず、DNSや公開中のサイトも変わりません。空欄なら、下の確認用アドレスに合わせます。', 'inputmode="url" placeholder="https://new.example.com"');
        $html .= '<p class="wrb-r-note wrb-r-preview">動作確認はこの機械だけで開ける確認用アドレス（<span data-preview-url></span>）で行います。確認中は、新しいアドレスへのリンクも確認用アドレスへ読み替えます。</p>';
        $html .= $secret('adminPassword', '確認用ログインのパスワードを決める（12文字以上）', '復元したサイトの確認だけに使う管理者を新しく作ります。元のサイトのログインは使いません。ログイン名は restore-admin です。', 'data-reveal');
        $html .= '<details><summary>詳細設定（通常は変更不要）</summary>';
        $html .= '<fieldset class="wrb-r-choice"><legend>画像などのメディア</legend><label><input type="radio" name="purpose" value="startup-check" checked> <strong>中身を検証するだけ</strong><span class="wrb-r-help">おすすめ。動作確認が早く終わります。画像は表示されません。</span></label><label><input type="radio" name="purpose" value="full"> <strong>すべて配置する</strong><span class="wrb-r-help">時間と容量が増えます。</span></label></fieldset>';
        $html .= '<div class="wrb-r-pair">' . $text('name', '復元の名前', '保存先のフォルダー名です。英小文字・数字・ハイフン。', 'maxlength="40"') . $text('previewPort', '確認用のポート番号', '1024〜65535。', 'inputmode="numeric" maxlength="5"') . '</div>';
        $html .= '<div class="wrb-r-pair">' . $text('adminLogin', '確認用ログインの名前', '', 'maxlength="60"', 'restore-admin') . $text('tablePrefix', '表の接頭辞', '', 'maxlength="64"', $page['prefix']) . '</div>';
        $html .= '<p class="wrb-r-field"><label for="wrb-r-aliases">元のサイトの別のアドレス（1行に1つ、最大8個）</label><span id="wrb-r-aliases-help" class="wrb-r-help">本文のリンクが別のアドレスを使っている場合に指定します。</span><textarea id="wrb-r-aliases" name="aliases" rows="2" autocomplete="off" spellcheck="false" aria-describedby="wrb-r-aliases-help"></textarea><span class="wrb-r-error" data-error-for="aliases" role="alert"></span></p>';
        $html .= $text('root', 'WordPressの保存ルート', '通常は空欄（r0001）です。', 'maxlength="5" placeholder="r0001"') . '</details></section>';

        $html .= '<section class="wrb-r-step" aria-labelledby="wrb-r-h-check"><h2 id="wrb-r-h-check"><span aria-hidden="true">4</span>確認して復元</h2><p class="wrb-r-help">検査だけでは何も作成しません。検査の後に内容を変えると、もう一度検査が必要です。</p><button type="button" class="wrb-r-button" data-action="preflight">内容を検査</button><div data-checks aria-live="polite"></div>';
        $html .= '<div data-confirm hidden><h3 class="wrb-r-subhead">この内容で復元します</h3><dl class="wrb-r-summary" data-summary></dl>';
        $html .= $text('confirmName', '確認のため、復元の名前を入力', '', 'maxlength="40"');
        if ($page['reauth']) { $html .= $secret('reauthPassword', 'あなたのWordPressパスワード', '本人確認のために入力します。'); }
        $html .= '<button type="button" class="wrb-r-button wrb-r-primary" data-action="start">新しい場所へ復元を開始</button></div></section>';
        $html .= '</form><aside class="wrb-r-side" aria-labelledby="wrb-r-h-run"><h2 id="wrb-r-h-run">進行状況</h2><p class="wrb-r-next" data-next aria-live="polite"></p><div data-status aria-live="polite"></div></aside></div>';
        return $html . '<p class="wrb-r-message" data-message role="status"></p></div>';
    }
}
