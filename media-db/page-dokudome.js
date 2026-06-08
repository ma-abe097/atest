/**
 * 独ドメげっと ページ
 * --------------------------------------------------------------------------
 * 会社名/担当者名/電話番号/業種から、その会社の公式サイト（独自ドメイン）だけを
 * 1件特定して表示する。除外ドメイン(ユーザー分)はストアに保存（管理者のみ）。
 */
(function () {
    const { createApp, ref, reactive, onMounted, nextTick, computed } = Vue;
    const { store, refreshIcons } = AppCore;
    const CSRF = window.__CSRF__ || '';
    const IS_ADMIN = window.__IS_ADMIN__ === true;

    createApp({
        setup() {
            const form      = reactive({ name: '', person: '', phone: '', industry: '' });
            const results   = ref([]);     // {name, found, url, domain, note, at}
            const searching = ref(false);
            const error     = ref('');

            // 除外ドメイン編集（1行1ドメイン）
            const excludeText  = ref((store.excludeDomains || []).join('\n'));
            const excludeCount = computed(() => (store.excludeDomains || []).length);

            const saveExclude = () => {
                const list = excludeText.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                store.excludeDomains = Array.from(new Set(list));   // 重複除去 → app-coreのwatchで自動保存
            };

            const runSearch = async () => {
                if (!IS_ADMIN) { alert('検索は管理者のみ実行できます。'); return; }
                if (!form.name.trim() && !form.phone.trim()) { alert('会社名または電話番号を入力してください。'); return; }
                if (searching.value) return;
                searching.value = true;
                error.value = '';
                try {
                    const res = await fetch('dokudome-search.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                        body: JSON.stringify({ name: form.name, person: form.person, phone: form.phone, industry: form.industry }),
                    });
                    let data = {};
                    try { data = await res.json(); } catch (e) { /* noop */ }
                    if (!res.ok || data.error) {
                        throw new Error(data.message || data.error || ('HTTP ' + res.status));
                    }
                    results.value.unshift({
                        name:   (form.name || form.phone || '(無題)'),
                        found:  !!data.found,
                        url:    data.url || '',
                        domain: data.domain || '',
                        note:   data.note || '',
                        at:     new Date().toLocaleString('ja-JP', { hour12: false }),
                    });
                    nextTick(refreshIcons);
                } catch (e) {
                    error.value = (e && e.message) ? e.message : String(e);
                    alert('検索に失敗しました：\n' + error.value);
                } finally {
                    searching.value = false;
                    nextTick(refreshIcons);
                }
            };

            const copyDomain = (r) => {
                const t = r.url || r.domain || '';
                if (t && navigator.clipboard) {
                    navigator.clipboard.writeText(t);
                }
            };

            // ヘッダーの「現在のリストをCSV出力」→ 検索結果を出力
            const exportCsv = () => {
                if (!results.value.length) { alert('出力する結果がありません。'); return; }
                const esc = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
                let csv = '会社名,独自ドメイン,URL,日時\n';
                results.value.forEach(r => {
                    csv += [r.name, r.found ? r.domain : '', r.found ? r.url : '', r.at].map(esc).join(',') + '\n';
                });
                const bom = new Uint8Array([0xEF, 0xBB, 0xBF]);
                const blob = new Blob([bom, csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = '独自ドメイン一覧.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            };

            onMounted(() => nextTick(refreshIcons));

            return { store, form, results, searching, error, excludeText, excludeCount, saveExclude, runSearch, copyDomain, exportCsv, isAdmin: IS_ADMIN };
        }
    }).mount('#app');
})();
