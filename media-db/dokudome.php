<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_login();

$data       = load_data();
$canEdit    = is_admin();   // 検索(有料API)・除外リスト編集は管理者のみ
$pageTitle  = '独ドメげっと';
$currentNav = 'dokudome';
$pageScript = 'page-dokudome.js';

require __DIR__ . '/layout_top.php';
?>
<div class="max-w-4xl mx-auto space-y-6">

    <!-- 説明 -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-start gap-4">
            <div class="bg-emerald-50 text-emerald-600 p-3 rounded-xl shrink-0"><i data-lucide="globe" class="w-7 h-7"></i></div>
            <div>
                <h2 class="text-xl font-bold text-gray-800">独ドメげっと</h2>
                <p class="text-sm text-gray-500 mt-1">会社名・担当者名・電話番号・業種から、その会社の<strong class="text-gray-700">本当の公式サイト（独自ドメイン）だけ</strong>を1件特定します。SNS・予約・ポータル・求人・口コミ・除外ドメインは自動で除外。見つからなければ空白です。</p>
            </div>
        </div>
    </div>

    <!-- 検索フォーム -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="block">
                <span class="block text-sm font-bold text-gray-700 mb-1">会社名</span>
                <input v-model="form.name" type="text" placeholder="例: 株式会社○○" @keyup.enter="runSearch" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            </label>
            <label class="block">
                <span class="block text-sm font-bold text-gray-700 mb-1">担当者名 <span class="text-gray-400 font-normal">（任意）</span></span>
                <input v-model="form.person" type="text" placeholder="例: 山田太郎" @keyup.enter="runSearch" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            </label>
            <label class="block">
                <span class="block text-sm font-bold text-gray-700 mb-1">電話番号 <span class="text-gray-400 font-normal">（任意・一致の手がかり）</span></span>
                <input v-model="form.phone" type="text" placeholder="例: 03-1234-5678" @keyup.enter="runSearch" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            </label>
            <label class="block">
                <span class="block text-sm font-bold text-gray-700 mb-1">業種 <span class="text-gray-400 font-normal">（任意）</span></span>
                <input v-model="form.industry" type="text" placeholder="例: 建設、飲食 など" @keyup.enter="runSearch" class="w-full border border-gray-300 rounded-md p-2.5 focus:ring-emerald-500 focus:border-emerald-500">
            </label>
        </div>
        <div class="mt-4 flex items-center gap-3 flex-wrap">
            <?php if ($canEdit): ?>
            <button @click="runSearch" :disabled="searching"
                    class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-5 py-2.5 rounded-lg shadow-sm disabled:opacity-50">
                <i :data-lucide="searching ? 'loader' : 'search'" :class="['w-5 h-5', searching ? 'sync-spin' : '']"></i>
                {{ searching ? '調べています…' : '独自ドメインを調べる' }}
            </button>
            <span class="text-xs text-gray-400">※ 有料APIを使用します（管理者のみ）</span>
            <?php else: ?>
            <span class="text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded px-3 py-2">検索は管理者のみ実行できます（閲覧は可能）。</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 結果 -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-bold text-gray-800">検索結果</h3>
            <span class="text-xs text-gray-400">{{ results.length }}件</span>
        </div>
        <div v-if="results.length === 0" class="p-8 text-center text-gray-400 text-sm">
            上のフォームで検索すると、ここに公式サイト（独自ドメイン）が表示されます。
        </div>
        <ul v-else class="divide-y divide-gray-100">
            <li v-for="(r, i) in results" :key="i" class="p-4 flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-gray-800 truncate">{{ r.name }}</p>
                    <template v-if="r.found">
                        <a :href="r.url" target="_blank" rel="noopener" class="text-emerald-700 hover:underline break-all">{{ r.url }}</a>
                        <span class="block text-xs text-gray-400">{{ r.domain }}</span>
                    </template>
                    <template v-else>
                        <span class="text-gray-400 text-sm">独自ドメインなし（空白）</span>
                    </template>
                </div>
                <span class="text-xs text-gray-400 shrink-0">{{ r.at }}</span>
                <button v-if="r.found" @click="copyDomain(r)" class="shrink-0 text-gray-500 hover:text-emerald-700 p-1.5 rounded hover:bg-gray-100" title="URLをコピー">
                    <i data-lucide="copy" class="w-4 h-4"></i>
                </button>
            </li>
        </ul>
    </div>

    <!-- 除外ドメイン -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
            <h3 class="font-bold text-gray-800 flex items-center gap-2"><i data-lucide="ban" class="w-5 h-5 text-rose-500"></i>除外ドメイン</h3>
            <span class="text-xs text-gray-400">SNS・予約・ポータル・求人・口コミ等は最初から自動で除外されます</span>
        </div>
        <?php if ($canEdit): ?>
        <p class="text-xs text-gray-500 mb-2">ここに追加したドメインも検索結果から除外します（1行に1つ。例: <code class="bg-gray-100 px-1 rounded">example.com</code>）。</p>
        <textarea v-model="excludeText" rows="6" placeholder="example.com&#10;sample.co.jp" class="w-full border border-gray-300 rounded-md p-3 text-sm font-mono focus:ring-emerald-500 focus:border-emerald-500"></textarea>
        <div class="mt-3 flex items-center gap-3">
            <button @click="saveExclude" class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-medium px-4 py-2 rounded-lg">
                <i data-lucide="save" class="w-4 h-4"></i> 保存
            </button>
            <span class="text-xs text-gray-400">現在 {{ excludeCount }} 件を追加除外中</span>
        </div>
        <?php else: ?>
        <ul class="text-sm text-gray-600 list-disc pl-5 space-y-0.5">
            <li v-for="(d, i) in store.excludeDomains" :key="i">{{ d }}</li>
            <li v-if="!store.excludeDomains.length" class="list-none text-gray-400">（追加の除外ドメインはありません）</li>
        </ul>
        <?php endif; ?>
    </div>

</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
