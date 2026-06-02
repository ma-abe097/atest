<?php
declare(strict_types=1);

/**
 * API番人さん (api_banto_san) — API棚卸しツール
 * --------------------------------------------------------------------------
 * Googleログイン + グループ/ロール権限つきの「コスト軸ダッシュボード」。
 *
 * 実装範囲:
 *   - Googleアカウント認証 (OAuth 2.0 / OpenID Connect)。パスワードは保持しない。
 *   - グループによるデータ分離。APIカタログは必ずいずれかのグループに属する。
 *   - ロール (owner/admin/member/viewer) を「サーバ側で必ず」権限チェック。
 *   - コスト軸ビュー（月額降順 / 通貨別小計 / 未設定の明示 / ドリルダウン / 絞り込み）。
 *   - APIカタログ・使用箇所の手動編集（member 以上）。viewer は閲覧のみ。
 *   - グループ作成・メンバー招待・ロール変更は groups.php。
 *
 * 重要な制約:
 *   - APIキー本体は絶対に保存しない。鍵の在りか(key_location) のみ記録する。
 *   - monthly_cost / notes / owner などは手動フィールド。
 *
 * 将来フェーズ（未実装）: スキャナCLI連携(scan/push, 手動フィールドのマージ保持) /
 *   各社 billing/usage API 連携による monthly_cost 半自動更新（仕様書 §6,§9）。
 */

require __DIR__ . '/bootstrap.php';

$route = $_GET['route'] ?? '';

/* ================================================================== *
 *  認証系ルート（リダイレクト/終了するものが中心）
 * ================================================================== */
if ($route === 'login') {
    if (!config('GOOGLE_CLIENT_ID')) {
        flash('err', 'Google OAuth が未設定です。config.local.php または環境変数を確認してください。');
        redirect(app_url());
    }
    redirect(google_login_url());
}

if ($route === 'oauth2callback') {
    try {
        google_handle_callback();
        flash('ok', 'ログインしました。');
    } catch (Throwable $e) {
        flash('err', 'ログインに失敗しました: ' . $e->getMessage());
    }
    redirect(app_url());
}

// ローカル検証用の簡易ログイン（既定OFF。本番では無効）
if ($route === 'devlogin') {
    if (!config_bool('APP_DEV_LOGIN')) {
        http_response_code(404);
        exit('Not found');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $name  = trim((string) ($_POST['name'] ?? '')) ?: ($email !== '' ? explode('@', $email)[0] : 'devuser');
        if ($email === '') {
            flash('err', 'メールアドレスを入力してください。');
            redirect(app_url());
        }
        login_with_profile('dev:' . strtolower($email), $email, $name, '');
        flash('ok', '（DEV）ログインしました。');
    }
    redirect(app_url());
}

if ($route === 'logout') {
    logout();
    redirect(app_url());
}

if ($route === 'switchgroup') {
    require_login();
    $gid = (int) ($_GET['gid'] ?? 0);
    if (set_current_group($gid)) {
        flash('ok', 'グループを切り替えました。');
    } else {
        flash('err', 'そのグループには所属していません。');
    }
    redirect(app_url());
}

/* ================================================================== *
 *  未ログイン: ログインページを表示して終了
 * ================================================================== */
if (!current_user()) {
    render_login_page();
    exit;
}

/* ================================================================== *
 *  ここからログイン済み。グループ・ロールを確定。
 * ================================================================== */
$user  = current_user();
$gid   = current_group_id();      // 所属グループ先頭に補正済み
$group = current_group();
$role  = current_role();

/* ------------------------------------------------------------------ *
 *  POST 処理（カタログ編集）— すべてサーバ側で権限チェック
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    // 編集系はすべて member 以上が必須
    if (in_array($action, ['save_api', 'delete_api', 'add_usage', 'delete_usage'], true)) {
        require_role_at_least($gid, 'member');
    }

    if ($action === 'save_api') {
        $id           = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $name         = trim((string) ($_POST['name'] ?? ''));
        $provider     = trim((string) ($_POST['provider'] ?? ''));
        $status       = array_key_exists($_POST['status'] ?? '', STATUSES) ? $_POST['status'] : 'unknown';
        $costRaw      = trim((string) ($_POST['monthly_cost'] ?? ''));
        $monthly_cost = ($costRaw === '') ? null : (float) $costRaw;
        $currency     = trim((string) ($_POST['currency'] ?? 'JPY')) ?: 'JPY';
        $billing_url  = trim((string) ($_POST['billing_url'] ?? ''));
        $key_location = trim((string) ($_POST['key_location'] ?? ''));
        $docs_url     = trim((string) ($_POST['docs_url'] ?? ''));
        $owner        = trim((string) ($_POST['owner'] ?? ''));
        $notes        = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '') {
            flash('err', 'API名は必須です。');
            redirect_self();
        }

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO apis (group_id, name, provider, status, monthly_cost, currency, billing_url, key_location, docs_url, owner, notes, created_at, updated_at)
                 VALUES (:gid,:name,:provider,:status,:cost,:cur,:bill,:key,:docs,:owner,:notes,:ca,:ua)'
            );
            $stmt->execute([
                ':gid'=>$gid, ':name'=>$name, ':provider'=>$provider, ':status'=>$status, ':cost'=>$monthly_cost,
                ':cur'=>$currency, ':bill'=>$billing_url, ':key'=>$key_location, ':docs'=>$docs_url,
                ':owner'=>$owner, ':notes'=>$notes, ':ca'=>now(), ':ua'=>now(),
            ]);
            flash('ok', 'APIを追加しました。');
        } else {
            // 現在グループに属する行のみ更新可（横断アクセス防止）
            $stmt = $pdo->prepare(
                'UPDATE apis SET name=:name, provider=:provider, status=:status, monthly_cost=:cost,
                     currency=:cur, billing_url=:bill, key_location=:key, docs_url=:docs,
                     owner=:owner, notes=:notes, updated_at=:ua
                 WHERE id=:id AND group_id=:gid'
            );
            $stmt->execute([
                ':name'=>$name, ':provider'=>$provider, ':status'=>$status, ':cost'=>$monthly_cost,
                ':cur'=>$currency, ':bill'=>$billing_url, ':key'=>$key_location, ':docs'=>$docs_url,
                ':owner'=>$owner, ':notes'=>$notes, ':ua'=>now(), ':id'=>$id, ':gid'=>$gid,
            ]);
            flash('ok', 'APIを更新しました。');
        }
        redirect_self();
    }

    if ($action === 'delete_api') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM apis WHERE id=:id AND group_id=:gid')->execute([':id'=>$id, ':gid'=>$gid]);
        flash('ok', 'APIを削除しました。');
        redirect_self();
    }

    if ($action === 'add_usage') {
        $aid  = (int) ($_POST['api_id'] ?? 0);
        // 対象 API が現在グループのものか検証
        $chk = $pdo->prepare('SELECT 1 FROM apis WHERE id=:id AND group_id=:gid');
        $chk->execute([':id'=>$aid, ':gid'=>$gid]);
        if ($chk->fetchColumn()) {
            $repo = trim((string) ($_POST['repo'] ?? ''));
            $file = trim((string) ($_POST['file'] ?? ''));
            $line = ($_POST['line'] ?? '') === '' ? null : (int) $_POST['line'];
            $snip = trim((string) ($_POST['snippet'] ?? ''));
            if ($repo !== '' || $file !== '') {
                $pdo->prepare('INSERT INTO usages (api_id, repo, file, line, snippet) VALUES (:aid,:repo,:file,:line,:snip)')
                    ->execute([':aid'=>$aid, ':repo'=>$repo, ':file'=>$file, ':line'=>$line, ':snip'=>$snip]);
                flash('ok', '使用箇所を追加しました。');
            }
        }
        redirect_self();
    }

    if ($action === 'delete_usage') {
        $uid = (int) ($_POST['id'] ?? 0);
        // 現在グループに属する API の usage のみ削除可
        $pdo->prepare(
            'DELETE FROM usages WHERE id=:id AND api_id IN (SELECT id FROM apis WHERE group_id=:gid)'
        )->execute([':id'=>$uid, ':gid'=>$gid]);
        flash('ok', '使用箇所を削除しました。');
        redirect_self();
    }

    redirect_self();
}

/* ------------------------------------------------------------------ *
 *  一覧取得（現在グループに限定 / フィルタ / コスト軸ソート）
 * ------------------------------------------------------------------ */
$pdo = db();

$q            = trim((string) ($_GET['q'] ?? ''));
$filterProv   = trim((string) ($_GET['provider'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

$where  = ['a.group_id = :gid'];
$params = [':gid' => $gid];
if ($q !== '') {
    $where[] = '(a.name LIKE :q OR a.notes LIKE :q OR a.owner LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($filterProv !== '') {
    $where[] = 'a.provider = :prov';
    $params[':prov'] = $filterProv;
}
if ($filterStatus !== '' && array_key_exists($filterStatus, STATUSES)) {
    $where[] = 'a.status = :st';
    $params[':st'] = $filterStatus;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT a.*,
               (SELECT COUNT(DISTINCT u.repo) FROM usages u WHERE u.api_id = a.id AND u.repo <> '') AS repo_count,
               (SELECT COUNT(*)               FROM usages u WHERE u.api_id = a.id)                  AS usage_count
        FROM apis a
        $whereSql
        ORDER BY (a.monthly_cost IS NULL) ASC, a.monthly_cost DESC, a.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apis = $stmt->fetchAll();

// 使用箇所（現在グループ分のみ）
$usagesByApi = [];
$uStmt = $pdo->prepare(
    'SELECT u.* FROM usages u JOIN apis a ON a.id = u.api_id WHERE a.group_id = :gid ORDER BY u.repo, u.file, u.line'
);
$uStmt->execute([':gid' => $gid]);
foreach ($uStmt->fetchAll() as $u) {
    $usagesByApi[(int) $u['api_id']][] = $u;
}

// 通貨別 月額小計 / 未設定件数
$subtotals = [];
$unsetCount = 0;
foreach ($apis as $a) {
    if ($a['monthly_cost'] === null) { $unsetCount++; continue; }
    $cur = $a['currency'] ?: 'JPY';
    $subtotals[$cur] = ($subtotals[$cur] ?? 0) + (float) $a['monthly_cost'];
}
ksort($subtotals);

$providers = $pdo->prepare("SELECT DISTINCT provider FROM apis WHERE group_id = :gid AND provider <> '' ORDER BY provider");
$providers->execute([':gid' => $gid]);
$providers = $providers->fetchAll(PDO::FETCH_COLUMN);

$memberships = my_memberships();
$flashMsg = take_flash();
$csrf = csrf_token();
$editable = can_edit();

/* ================================================================== *
 *  ビュー
 * ================================================================== */
function fmt_money(?float $cost, string $cur): string
{
    if ($cost === null) {
        return '<span class="muted">未設定</span>';
    }
    $n = (fmod($cost, 1.0) === 0.0) ? number_format($cost) : number_format($cost, 2);
    return h($cur) . ' ' . $n;
}

/** 共通スタイル */
function render_styles(): void { ?>
<style>
    :root {
        --bg:#f5f6f8; --card:#fff; --line:#e3e6ea; --ink:#1f2733;
        --muted:#8a93a0; --accent:#2563eb; --accent-d:#1d4ed8;
        --ok-bg:#e7f6ec; --ok-ink:#1a7f43; --err-bg:#fdecec; --err-ink:#b42318;
    }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--bg); color:var(--ink);
        font-family:-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif; line-height:1.6; }
    header.app { background:#0f172a; color:#fff; padding:12px 20px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    header.app h1 { font-size:18px; margin:0; font-weight:700; }
    header.app .tag { font-size:12px; color:#94a3b8; }
    header.app .spacer { flex:1; }
    header.app .who { display:flex; align-items:center; gap:8px; font-size:13px; }
    header.app .who img { width:26px; height:26px; border-radius:50%; }
    header.app select { padding:5px 8px; border-radius:8px; border:1px solid #334155; background:#1e293b; color:#fff; font-size:13px; }
    header.app a.navlink { color:#cbd5e1; text-decoration:none; font-size:13px; padding:4px 8px; border-radius:7px; }
    header.app a.navlink:hover { background:#1e293b; color:#fff; }
    .wrap { max-width:1080px; margin:0 auto; padding:20px; }
    .role-badge { font-size:11px; padding:2px 8px; border-radius:999px; background:#1e293b; color:#cbd5e1; }
    .summary { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
    .stat { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px 16px; min-width:150px; }
    .stat .label { font-size:12px; color:var(--muted); }
    .stat .value { font-size:22px; font-weight:700; }
    .stat .value small { font-size:12px; font-weight:400; color:var(--muted); }
    .toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:var(--card);
        border:1px solid var(--line); border-radius:12px; padding:12px; margin-bottom:14px; }
    .toolbar input, .toolbar select { padding:7px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; }
    .toolbar .spacer { flex:1; }
    button, .btn { font-size:14px; padding:7px 14px; border-radius:8px; border:1px solid var(--line);
        background:#fff; color:var(--ink); cursor:pointer; text-decoration:none; display:inline-block; }
    button.primary, .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
    button.primary:hover { background:var(--accent-d); }
    button.danger { color:var(--err-ink); border-color:#f3c4c0; background:#fff; }
    button.link { border:none; background:none; color:var(--accent); padding:2px 4px; }
    table { width:100%; border-collapse:collapse; background:var(--card); border-radius:12px; overflow:hidden; }
    th, td { padding:10px 12px; text-align:left; border-bottom:1px solid var(--line); font-size:14px; vertical-align:top; }
    th { background:#f0f2f5; font-size:12px; color:var(--muted); font-weight:600; }
    td.cost { font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:600; }
    tr.api-row:hover { background:#fafbfc; }
    .muted { color:var(--muted); }
    .pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
    .pill.active{background:#e7f6ec;color:#1a7f43;} .pill.unused{background:#eef1f4;color:#6b7280;}
    .pill.unknown{background:#fff4e0;color:#b45309;} .pill.deprecated{background:#fdecec;color:#b42318;}
    .usages { background:#fbfcfe; }
    .usages table { box-shadow:none; border:1px solid var(--line); margin:6px 0; }
    .usages td, .usages th { font-size:13px; padding:6px 10px; }
    code { background:#f0f2f5; padding:1px 5px; border-radius:5px; font-size:12.5px; word-break:break-all; }
    .flash { padding:10px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; }
    .flash.ok { background:var(--ok-bg); color:var(--ok-ink); } .flash.err { background:var(--err-bg); color:var(--err-ink); }
    dialog { border:none; border-radius:14px; padding:0; width:min(620px,94vw); box-shadow:0 20px 60px rgba(0,0,0,.25); }
    dialog::backdrop { background:rgba(15,23,42,.45); }
    .modal-head { padding:16px 20px; border-bottom:1px solid var(--line); font-weight:700; }
    .modal-body { padding:16px 20px; }
    .modal-foot { padding:14px 20px; border-top:1px solid var(--line); display:flex; justify-content:flex-end; gap:8px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid .full { grid-column:1 / -1; }
    .field label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
    .field input, .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; }
    .hint { font-size:11.5px; color:var(--muted); margin-top:3px; }
    .empty { text-align:center; color:var(--muted); padding:40px; }
    .note-cell { max-width:220px; }
    .login-box { max-width:420px; margin:8vh auto; background:var(--card); border:1px solid var(--line);
        border-radius:16px; padding:32px; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.06); }
    .gbtn { display:inline-flex; align-items:center; gap:10px; padding:11px 18px; border:1px solid var(--line);
        border-radius:10px; background:#fff; color:#1f2733; text-decoration:none; font-weight:600; font-size:15px; }
    .gbtn:hover { background:#f8fafc; }
    @media (max-width:720px){ .grid{grid-template-columns:1fr;} .hide-sm{display:none;} }
</style>
<?php }

/** ログインページ */
function render_login_page(): void
{
    $flashMsg = take_flash();
    $hasGoogle = (bool) config('GOOGLE_CLIENT_ID');
    $devLogin  = config_bool('APP_DEV_LOGIN');
    $csrf = csrf_token();
    ?>
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — ログイン</title>
<?php render_styles(); ?>
</head><body>
<header class="app"><h1>🛡️ <?= h(APP_NAME) ?></h1><span class="tag">API棚卸しダッシュボード</span></header>
<div class="wrap">
    <?php if ($flashMsg): ?><div class="flash <?= h($flashMsg[0]) ?>"><?= h($flashMsg[1]) ?></div><?php endif; ?>
    <div class="login-box">
        <h2 style="margin-top:0">ログイン</h2>
        <p class="muted">Googleアカウントでログインしてください。<br>パスワードは保持しません。</p>
        <?php if ($hasGoogle): ?>
            <p><a class="gbtn" href="<?= h(app_url('login')) ?>">
                <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.6 2.4 30.1 0 24 0 14.6 0 6.4 5.4 2.5 13.3l7.9 6.1C12.2 13.2 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.5 3-2.2 5.5-4.7 7.2l7.3 5.7C43.9 38 46.5 31.8 46.5 24.5z"/><path fill="#FBBC05" d="M10.4 28.6c-.5-1.5-.8-3-.8-4.6s.3-3.1.8-4.6l-7.9-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.5 10.7l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.1 0 11.3-2 15-5.5l-7.3-5.7c-2 1.4-4.7 2.3-7.7 2.3-6.4 0-11.8-3.7-13.6-9.4l-7.9 6.1C6.4 42.6 14.6 48 24 48z"/></svg>
                Googleでログイン
            </a></p>
        <?php else: ?>
            <div class="flash err">Google OAuth が未設定です。<code>config.local.php</code> を作成し、クライアントID/シークレット/リダイレクトURIを設定してください（<code>config.local.php.example</code> 参照）。</div>
        <?php endif; ?>

        <?php if ($devLogin): ?>
            <hr style="margin:22px 0; border:none; border-top:1px solid var(--line)">
            <p class="hint">開発用ログイン（APP_DEV_LOGIN 有効時のみ。本番では無効化してください）</p>
            <form method="post" action="<?= h(app_url('devlogin')) ?>" style="display:flex; flex-direction:column; gap:8px; text-align:left">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input name="email" type="email" placeholder="email" required style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                <input name="name" placeholder="表示名（任意）" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                <button class="primary" type="submit">（DEV）ログイン</button>
            </form>
        <?php endif; ?>
    </div>
</div></body></html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — <?= h($group['name']) ?></title>
<?php render_styles(); ?>
</head>
<body>
<header class="app">
    <h1>🛡️ <?= h(APP_NAME) ?></h1>
    <span class="tag">コスト軸ダッシュボード</span>
    <span class="spacer"></span>
    <!-- グループ切替 -->
    <?php if (count($memberships) > 1): ?>
        <select onchange="if(this.value)location.href=this.value">
            <?php foreach ($memberships as $m): ?>
                <option value="<?= h(app_url('switchgroup', ['gid' => (int) $m['id']])) ?>" <?= (int) $m['id'] === $gid ? 'selected' : '' ?>>
                    <?= h($m['name']) ?>（<?= h(ROLES[$m['role']] ?? $m['role']) ?>）
                </option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <span class="tag"><?= h($group['name']) ?></span>
    <?php endif; ?>
    <span class="role-badge"><?= h(ROLES[$role] ?? $role) ?></span>
    <a class="navlink" href="groups.php">グループ管理</a>
    <span class="who">
        <?php if ($user['avatar_url']): ?><img src="<?= h($user['avatar_url']) ?>" alt=""><?php endif; ?>
        <?= h($user['name'] ?: $user['email']) ?>
    </span>
    <a class="navlink" href="<?= h(app_url('logout')) ?>">ログアウト</a>
</header>

<div class="wrap">

    <?php if ($flashMsg): ?>
        <div class="flash <?= h($flashMsg[0]) ?>"><?= h($flashMsg[1]) ?></div>
    <?php endif; ?>

    <?php if (!$editable): ?>
        <div class="flash" style="background:#eef4ff;color:#1d4ed8">あなたは <strong>閲覧者(viewer)</strong> です。編集はできません。</div>
    <?php endif; ?>

    <!-- 月額合計サマリ -->
    <div class="summary">
        <?php if ($subtotals): ?>
            <?php foreach ($subtotals as $cur => $sum): ?>
                <div class="stat">
                    <div class="label">月額合計（<?= h($cur) ?>）</div>
                    <div class="value"><?= h($cur) ?> <?= number_format($sum, (fmod($sum,1.0)===0.0)?0:2) ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="stat"><div class="label">月額合計</div><div class="value">—</div></div>
        <?php endif; ?>
        <div class="stat"><div class="label">登録API数</div><div class="value"><?= count($apis) ?> <small>件</small></div></div>
        <div class="stat"><div class="label">金額未設定</div><div class="value"><?= $unsetCount ?> <small>件（合計に含まず）</small></div></div>
    </div>

    <!-- 絞り込み / 検索 -->
    <form class="toolbar" method="get">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="名前 / メモ / 担当者で検索">
        <select name="provider">
            <option value="">provider（すべて）</option>
            <?php foreach ($providers as $p): ?>
                <option value="<?= h($p) ?>" <?= $p === $filterProv ? 'selected' : '' ?>><?= h($p) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">status（すべて）</option>
            <?php foreach (STATUSES as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= $k === $filterStatus ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="primary" type="submit">絞り込み</button>
        <a class="btn" href="index.php">クリア</a>
        <span class="spacer"></span>
        <?php if ($editable): ?>
            <button type="button" class="primary" onclick="openCreate()">＋ API を追加</button>
        <?php endif; ?>
    </form>

    <!-- コスト軸ビュー -->
    <?php if (!$apis): ?>
        <div class="empty">該当するAPIがありません。<?= $editable ? '「＋ API を追加」から登録してください。' : '' ?></div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width:32px"></th>
                <th>API名</th>
                <th class="hide-sm">provider</th>
                <th>月額</th>
                <th class="hide-sm">使用リポジトリ</th>
                <th>status</th>
                <th class="hide-sm note-cell">メモ / 担当</th>
                <?php if ($editable): ?><th style="width:120px"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($apis as $a):
            $aid = (int) $a['id'];
            $uses = $usagesByApi[$aid] ?? [];
        ?>
            <tr class="api-row">
                <td>
                    <?php if ($uses): ?>
                        <button class="link" type="button" onclick="toggleUsage(<?= $aid ?>)" id="tg<?= $aid ?>" title="使用箇所を表示">▶</button>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= h($a['name']) ?></strong>
                    <?php if ($a['docs_url']): ?><a href="<?= h($a['docs_url']) ?>" target="_blank" rel="noopener" class="muted" title="ドキュメント">📄</a><?php endif; ?>
                    <?php if ($a['key_location']): ?><div class="hint">🔑 <?= h($a['key_location']) ?></div><?php endif; ?>
                </td>
                <td class="hide-sm"><?= h($a['provider']) ?: '<span class="muted">—</span>' ?></td>
                <td class="cost">
                    <?= fmt_money($a['monthly_cost'] === null ? null : (float) $a['monthly_cost'], $a['currency']) ?>
                    <?php if ($a['billing_url']): ?><a href="<?= h($a['billing_url']) ?>" target="_blank" rel="noopener" class="muted" title="請求ページ">💳</a><?php endif; ?>
                </td>
                <td class="hide-sm"><?= (int) $a['repo_count'] ?> <span class="muted">リポジトリ / <?= (int) $a['usage_count'] ?> 箇所</span></td>
                <td><span class="pill <?= h($a['status']) ?>"><?= h(STATUSES[$a['status']] ?? $a['status']) ?></span></td>
                <td class="hide-sm note-cell">
                    <?php if ($a['owner']): ?><div class="muted">👤 <?= h($a['owner']) ?></div><?php endif; ?>
                    <?= nl2br(h(mb_strimwidth($a['notes'], 0, 60, '…'))) ?>
                </td>
                <?php if ($editable): ?>
                <td>
                    <button class="link" type="button"
                        onclick='openEdit(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>編集</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('「<?= h($a['name']) ?>」を削除しますか？')">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete_api">
                        <input type="hidden" name="id" value="<?= $aid ?>">
                        <button class="link danger" type="submit">削除</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>

            <!-- ドリルダウン：使用箇所 -->
            <tr class="usages" id="us<?= $aid ?>" style="display:none">
                <td colspan="8">
                    <table>
                        <thead><tr><th>repo</th><th>file</th><th>line</th><th>snippet</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($uses as $u): ?>
                            <tr>
                                <td><?= h($u['repo']) ?></td>
                                <td><?= h($u['file']) ?></td>
                                <td><?= $u['line'] !== null ? (int) $u['line'] : '' ?></td>
                                <td><?php if ($u['snippet'] !== ''): ?><code><?= h($u['snippet']) ?></code><?php endif; ?></td>
                                <?php if ($editable): ?>
                                <td>
                                    <form method="post" onsubmit="return confirm('この使用箇所を削除しますか？')">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_usage">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button class="link danger" type="submit">削除</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($editable): ?>
                    <form method="post" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="add_usage">
                        <input type="hidden" name="api_id" value="<?= $aid ?>">
                        <input name="repo" placeholder="repo" style="padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <input name="file" placeholder="file" style="padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <input name="line" placeholder="line" type="number" style="width:80px;padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <input name="snippet" placeholder="snippet（任意）" style="flex:1;min-width:160px;padding:6px 8px;border:1px solid var(--line);border-radius:7px">
                        <button type="submit">使用箇所を追加</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="hint" style="margin-top:18px">
        ※ コストは手動入力（v1）。使用箇所(usages)は本来スキャナCLIが自動検出してプッシュします。
        APIキー本体は保存せず、鍵の在りか(<code>env: XXX</code> 等)のみを記録します。
        スキャナ連携・各社billing API連携は将来フェーズ（仕様書 §6,§9）。
    </p>
</div>

<?php if ($editable): ?>
<!-- 追加 / 編集モーダル -->
<dialog id="apiDialog">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_api">
        <input type="hidden" name="id" id="f_id" value="">
        <div class="modal-head" id="modalTitle">API を追加</div>
        <div class="modal-body">
            <div class="grid">
                <div class="field"><label>API名 <span style="color:#b42318">*</span></label><input name="name" id="f_name" required placeholder="例: OpenAI API"></div>
                <div class="field"><label>provider</label><input name="provider" id="f_provider" placeholder="例: OpenAI / Stripe / Google"></div>
                <div class="field"><label>月額（空欄＝未設定）</label><input name="monthly_cost" id="f_cost" type="number" step="0.01" min="0" placeholder="例: 12000"></div>
                <div class="field"><label>通貨</label><select name="currency" id="f_currency"><?php foreach (['JPY','USD','EUR','GBP'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>status</label><select name="status" id="f_status"><?php foreach (STATUSES as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>担当 (owner)</label><input name="owner" id="f_owner" placeholder="例: 開発チーム"></div>
                <div class="field full"><label>鍵の在りか (key_location)</label><input name="key_location" id="f_key" placeholder="例: env: OPENAI_API_KEY"><div class="hint">⚠ APIキー本体は入力しないでください。環境変数名など「在りか」のみ。</div></div>
                <div class="field"><label>請求ページURL (billing_url)</label><input name="billing_url" id="f_billing" type="url" placeholder="https://..."></div>
                <div class="field"><label>ドキュメントURL (docs_url)</label><input name="docs_url" id="f_docs" type="url" placeholder="https://..."></div>
                <div class="field full"><label>メモ (notes)</label><textarea name="notes" id="f_notes" rows="3" placeholder="補足・コスト変動の理由など"></textarea></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" onclick="document.getElementById('apiDialog').close()">キャンセル</button>
            <button type="submit" class="primary">保存</button>
        </div>
    </form>
</dialog>
<script>
    const dialog = document.getElementById('apiDialog');
    function openCreate() {
        document.getElementById('modalTitle').textContent = 'API を追加';
        document.getElementById('f_id').value = '';
        for (const f of ['name','provider','cost','owner','key','billing','docs','notes']) {
            const el = document.getElementById('f_' + f); if (el) el.value = '';
        }
        document.getElementById('f_currency').value = 'JPY';
        document.getElementById('f_status').value = 'unknown';
        dialog.showModal();
    }
    function openEdit(a) {
        document.getElementById('modalTitle').textContent = 'API を編集';
        document.getElementById('f_id').value      = a.id ?? '';
        document.getElementById('f_name').value     = a.name ?? '';
        document.getElementById('f_provider').value = a.provider ?? '';
        document.getElementById('f_cost').value     = (a.monthly_cost === null || a.monthly_cost === undefined) ? '' : a.monthly_cost;
        document.getElementById('f_currency').value = a.currency ?? 'JPY';
        document.getElementById('f_status').value   = a.status ?? 'unknown';
        document.getElementById('f_owner').value    = a.owner ?? '';
        document.getElementById('f_key').value      = a.key_location ?? '';
        document.getElementById('f_billing').value  = a.billing_url ?? '';
        document.getElementById('f_docs').value     = a.docs_url ?? '';
        document.getElementById('f_notes').value    = a.notes ?? '';
        dialog.showModal();
    }
</script>
<?php endif; ?>
<script>
    function toggleUsage(id) {
        const row = document.getElementById('us' + id);
        const tg  = document.getElementById('tg' + id);
        const open = row.style.display === 'none';
        row.style.display = open ? 'table-row' : 'none';
        if (tg) tg.textContent = open ? '▼' : '▶';
    }
</script>
</body>
</html>
