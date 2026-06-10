<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$canView = is_admin();   // ログ閲覧・設定は管理者（全権限）のみ

// 全ログをCSVでダウンロード（管理者のみ）
if ($canView && ($_GET['download'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity_log.csv"');
    echo "\xEF\xBB\xBF";   // ExcelのBOM（文字化け対策）
    $out = fopen('php://output', 'w');
    fputcsv($out, ['日時', 'ユーザー', 'ログインID', '権限', '操作', '詳細', 'IP']);
    foreach (mdb_read_log(0) as $e) {
        fputcsv($out, [$e['t'] ?? '', $e['user'] ?? '', $e['loginId'] ?? '', $e['role'] ?? '', $e['action'] ?? '', $e['detail'] ?? '', $e['ip'] ?? '']);
    }
    fclose($out);
    exit;
}

$data       = load_data();
$pageTitle  = '設定・ログ';
$currentNav = 'settings';
$pageScript = 'page-settings.js';

require __DIR__ . '/layout_top.php';

$roleLabel = static function (string $r): string {
    return ['admin' => '管理者', 'manager' => 'API利用可', 'member' => '閲覧のみ'][$r] ?? ($r !== '' ? $r : '—');
};
?>
<div class="max-w-6xl mx-auto space-y-6">
    <?php if (!$canView): ?>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-10 text-center">
            <i data-lucide="lock" class="w-10 h-10 mx-auto text-gray-300 mb-3"></i>
            <h2 class="text-xl font-bold text-gray-800">管理者専用ページです</h2>
            <p class="text-sm text-gray-500 mt-2">このページ（設定・ログ）は管理者（全権限）のみが閲覧できます。</p>
        </div>
    <?php else:
        $logs = mdb_read_log(1000);
        $all  = mdb_read_log(0);
    ?>
        <!-- 説明 -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-start gap-4">
                    <div class="bg-indigo-50 text-indigo-600 p-3 rounded-xl shrink-0"><i data-lucide="shield" class="w-7 h-7"></i></div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-800">操作ログ（管理者専用）</h2>
                        <p class="text-sm text-gray-500 mt-1">ログイン・データ編集・API（他媒体検索／独ドメげっと）など、サイト内の操作をすべて記録しています。全 <strong class="text-gray-700"><?= count($all) ?></strong> 件。</p>
                    </div>
                </div>
                <a href="settings.php?download=1" class="inline-flex items-center gap-1.5 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2 hover:bg-green-100 shrink-0">
                    <i data-lucide="download" class="w-4 h-4"></i> 全ログをCSVダウンロード
                </a>
            </div>
        </div>

        <!-- ログ表 -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-6 py-3 border-b border-gray-200 text-sm text-gray-500">最新 <?= count($logs) ?> 件を表示（それより古い分は「全ログをCSVダウンロード」で確認できます）</div>
            <?php if (count($logs) === 0): ?>
                <div class="p-10 text-center text-gray-400">まだログがありません。</div>
            <?php else: ?>
            <div class="overflow-x-auto max-h-[70vh] overflow-y-auto">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-600 bg-gray-50 border-b border-gray-200 sticky top-0">
                        <tr>
                            <th class="px-4 py-2 font-bold">日時</th>
                            <th class="px-4 py-2 font-bold">ユーザー</th>
                            <th class="px-4 py-2 font-bold">権限</th>
                            <th class="px-4 py-2 font-bold">操作</th>
                            <th class="px-4 py-2 font-bold">詳細</th>
                            <th class="px-4 py-2 font-bold">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $e): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-500"><?= h($e['t'] ?? '') ?></td>
                            <td class="px-4 py-2 font-medium text-gray-800"><?= h($e['user'] ?? '') ?><?php if (!empty($e['loginId'])): ?><span class="text-gray-400 font-normal"> (<?= h($e['loginId']) ?>)</span><?php endif; ?></td>
                            <td class="px-4 py-2 text-gray-500"><?= h($roleLabel((string) ($e['role'] ?? ''))) ?></td>
                            <td class="px-4 py-2"><span class="text-xs font-medium bg-blue-50 text-blue-700 px-2 py-0.5 rounded"><?= h($e['action'] ?? '') ?></span></td>
                            <td class="px-4 py-2 text-gray-600"><?= h($e['detail'] ?? '') ?></td>
                            <td class="px-4 py-2 text-gray-400 text-xs"><?= h($e['ip'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
