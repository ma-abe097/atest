<?php
declare(strict_types=1);

/**
 * 入口（ログイン / ランディング）
 * --------------------------------------------------------------------------
 *  - ?action=logout … ログアウトして自身へ戻る
 *  - ログイン済み    … dashboard.php へリダイレクト
 *  - 未ログイン      … ログインフォームを表示（POSTでPHPセッションを確立）
 */

require __DIR__ . '/bootstrap.php';

// ログアウト
if (($_GET['action'] ?? '') === 'logout') {
    do_logout();
    redirect('index.php');
}

// すでにログイン済みならダッシュボードへ
if (current_user()) {
    redirect('dashboard.php');
}

$error = '';

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'セッションが切れました。もう一度お試しください。';
    } else {
        $user = find_user_by_credentials(trim((string) ($_POST['loginId'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($user) {
            do_login($user);
            redirect('dashboard.php');
        }
        $error = 'IDまたはパスワードが間違っています。';
    }
}

// 初回アクセス時に data.json（初期データ）を用意しておく
load_data();
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
