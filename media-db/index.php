<?php
declare(strict_types=1);

/**
 * 入口（ログイン / ホーム）
 * --------------------------------------------------------------------------
 *  - ?action=logout … ログアウトして自身へ戻る
 *  - 未ログイン      … ログインフォームを表示（POSTでPHPセッションを確立）
 *  - ログイン済み    … ホーム（TOPページ）を表示
 */

require __DIR__ . '/bootstrap.php';

// ログアウト
if (($_GET['action'] ?? '') === 'logout') {
    do_logout();
    redirect('index.php');
}

$user  = current_user();
$error = '';

// ログイン処理（未ログイン時のみ）
if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'セッションが切れました。もう一度お試しください。';
    } else {
        $found = find_user_by_credentials(trim((string) ($_POST['loginId'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($found) {
            do_login($found);
            redirect('index.php');
        }
        $error = 'IDまたはパスワードが間違っています。';
    }
    $user = current_user();
}

/* ============================================================
 *  ログイン済み → ホーム（TOPページ）
 * ============================================================ */
if ($user) {
    $data          = load_data();
    $clients       = $data['clients'];
    $clientCount   = count($clients);
    $mediaCount    = count($data['media']);
    $searchedCount = count(array_filter($clients, static fn($c) => !empty($c['searchedAt'])));
    $unsearched    = max(0, $clientCount - $searchedCount);

    $pageTitle  = 'ホーム';
    $currentNav = 'home';
    $pageScript = 'page-home.js';
    require __DIR__ . '/layout_top.php';
    ?>
    <div class="max-w-6xl mx-auto space-y-8">

        <!-- ようこそ（ヒーロー） -->
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 p-8 text-white shadow-lg">
            <div class="relative z-10">
                <p class="text-blue-100 text-sm mb-1">ようこそ、<?= h($user['name'] ?? '') ?> さん</p>
                <h2 class="text-2xl md:text-3xl font-bold mb-2"><?= h(MDB_APP_NAME) ?></h2>
                <p class="text-blue-100 max-w-2xl">
                    受注した顧客が「他にどんな媒体を使っているか」を調べ、よく併用されている媒体をランキングする社内ツールです。
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="register.php" class="inline-flex items-center gap-2 bg-white text-blue-700 font-medium px-4 py-2 rounded-lg hover:bg-blue-50 shadow-sm">
                        <i data-lucide="upload" class="w-4 h-4"></i> 受注データを取り込む
                    </a>
                    <a href="dashboard.php" class="inline-flex items-center gap-2 bg-blue-500/30 border border-white/40 text-white font-medium px-4 py-2 rounded-lg hover:bg-blue-500/50">
                        <i data-lucide="layout-dashboard" class="w-4 h-4"></i> 受注一覧・ランキングを見る
                    </a>
                </div>
            </div>
            <i data-lucide="database" class="absolute -right-6 -bottom-6 w-40 h-40 text-white/10"></i>
        </div>

        <!-- 現状サマリー -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 text-gray-500 text-sm mb-2"><i data-lucide="building-2" class="w-4 h-4"></i> 登録顧客数</div>
                <p class="text-3xl font-bold text-gray-800"><?= $clientCount ?><span class="text-base font-normal text-gray-400 ml-1">社</span></p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 text-gray-500 text-sm mb-2"><i data-lucide="list" class="w-4 h-4"></i> 登録媒体数</div>
                <p class="text-3xl font-bold text-gray-800"><?= $mediaCount ?><span class="text-base font-normal text-gray-400 ml-1">件</span></p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 text-gray-500 text-sm mb-2"><i data-lucide="search-check" class="w-4 h-4"></i> 他媒体 調査済み</div>
                <p class="text-3xl font-bold text-green-600"><?= $searchedCount ?><span class="text-base font-normal text-gray-400 ml-1">社</span></p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
                <div class="flex items-center gap-2 text-gray-500 text-sm mb-2"><i data-lucide="search" class="w-4 h-4"></i> 未調査</div>
                <p class="text-3xl font-bold text-amber-500"><?= $unsearched ?><span class="text-base font-normal text-gray-400 ml-1">社</span></p>
            </div>
        </div>

        <!-- メニュー -->
        <div>
            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center"><i data-lucide="layout-grid" class="w-5 h-5 mr-2 text-blue-600"></i>メニュー</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <a href="register.php" class="group bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-blue-300 transition flex items-start gap-4">
                    <div class="bg-blue-50 text-blue-600 p-3 rounded-lg shrink-0"><i data-lucide="database-zap" class="w-6 h-6"></i></div>
                    <div>
                        <h4 class="font-bold text-gray-800 group-hover:text-blue-700">データ登録・読込</h4>
                        <p class="text-sm text-gray-500 mt-1">CSV/Excelから受注を取り込み。顧客の追加・削除もここ。</p>
                    </div>
                </a>
                <a href="dashboard.php" class="group bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-blue-300 transition flex items-start gap-4">
                    <div class="bg-indigo-50 text-indigo-600 p-3 rounded-lg shrink-0"><i data-lucide="layout-dashboard" class="w-6 h-6"></i></div>
                    <div>
                        <h4 class="font-bold text-gray-800 group-hover:text-blue-700">受注一覧・ランキング</h4>
                        <p class="text-sm text-gray-500 mt-1">受注客の他媒体を調べ、ドメイン重複ランキングを表示。</p>
                    </div>
                </a>
                <a href="flag-search.php" class="group bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-blue-300 transition flex items-start gap-4">
                    <div class="bg-purple-50 text-purple-600 p-3 rounded-lg shrink-0"><i data-lucide="filter" class="w-6 h-6"></i></div>
                    <div>
                        <h4 class="font-bold text-gray-800 group-hover:text-blue-700">フラグ(媒体)別検索</h4>
                        <p class="text-sm text-gray-500 mt-1">リスト元ごとに、顧客が併用している媒体ランキングを表示。</p>
                    </div>
                </a>
                <a href="accounts.php" class="group bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-blue-300 transition flex items-start gap-4">
                    <div class="bg-gray-100 text-gray-600 p-3 rounded-lg shrink-0"><i data-lucide="users" class="w-6 h-6"></i></div>
                    <div>
                        <h4 class="font-bold text-gray-800 group-hover:text-blue-700">アカウント管理</h4>
                        <p class="text-sm text-gray-500 mt-1">ログインアカウントの追加・編集・削除。</p>
                    </div>
                </a>
            </div>
        </div>

        <!-- かんたんな使い方 -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center"><i data-lucide="footprints" class="w-5 h-5 mr-2 text-blue-600"></i>かんたんな使い方</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div class="flex gap-3">
                    <div class="w-7 h-7 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold shrink-0">1</div>
                    <div><p class="font-medium text-gray-800">受注を取り込む</p><p class="text-gray-500 mt-0.5">「データ登録・読込」でFileMakerのCSVを取り込み。</p></div>
                </div>
                <div class="flex gap-3">
                    <div class="w-7 h-7 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold shrink-0">2</div>
                    <div><p class="font-medium text-gray-800">他媒体を調べる</p><p class="text-gray-500 mt-0.5">「受注一覧」で各社の「他媒体を調べる」を実行。</p></div>
                </div>
                <div class="flex gap-3">
                    <div class="w-7 h-7 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold shrink-0">3</div>
                    <div><p class="font-medium text-gray-800">ランキングを見る</p><p class="text-gray-500 mt-0.5">よく併用されている媒体ドメインが多い順に表示。</p></div>
                </div>
            </div>
        </div>

    </div>
    <?php
    require __DIR__ . '/layout_bottom.php';
    exit;
}

/* ============================================================
 *  未ログイン → ログイン画面
 * ============================================================ */
load_data();   // 初回アクセス時に初期データを用意
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | <?= h(MDB_APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>[v-cloak]{display:none}</style>
</head>
<body class="bg-gray-800 text-gray-800 font-sans h-screen flex items-center justify-center">

    <div class="bg-white p-8 rounded-xl shadow-2xl w-full max-w-sm">
        <div class="text-center mb-6">
            <div class="bg-blue-600 text-white p-3 rounded-full inline-block mb-3">
                <i data-lucide="lock" class="w-8 h-8"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">管理システム ログイン</h2>
            <p class="text-sm text-gray-500 mt-1"><?= h(MDB_APP_NAME) ?></p>
        </div>

        <form method="post" action="index.php" class="space-y-4">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ログインID</label>
                <input type="text" name="loginId" required autofocus
                       class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">パスワード</label>
                <div class="relative">
                    <input type="password" name="password" id="password" required
                           class="w-full border border-gray-300 rounded-lg p-2.5 focus:ring-blue-500 focus:border-blue-500 pr-10">
                    <button type="button" id="togglePw"
                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-500 hover:text-blue-600">
                        <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <?php if ($error !== ''): ?>
                <p class="text-red-500 text-sm font-medium"><?= h($error) ?></p>
            <?php endif; ?>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 transition">
                ログイン
            </button>
        </form>
    </div>

    <script>
        // パスワード表示切り替え
        (function () {
            const btn = document.getElementById('togglePw');
            const input = document.getElementById('password');
            btn.addEventListener('click', function () {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.innerHTML = '<i data-lucide="' + (show ? 'eye-off' : 'eye') + '" class="w-5 h-5"></i>';
                window.lucide.createIcons();
            });
            window.lucide.createIcons();
        })();
    </script>
</body>
</html>
