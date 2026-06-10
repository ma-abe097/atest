/**
 * 設定・ログ ページ（管理者専用）の最小 Vue アプリ
 * --------------------------------------------------------------------------
 * ログ表はPHPで描画済み。ここは共通ヘッダー（保存インジケーター / CSV出力ボタン）を
 * 動かすための最小限。ヘッダーのCSVボタンは「全ログCSVダウンロード」に割り当てる。
 */
(function () {
    const { createApp, onMounted, nextTick } = Vue;
    const { store, refreshIcons } = AppCore;

    createApp({
        setup() {
            // ヘッダーの「現在のリストをCSV出力」→ 全ログをダウンロード
            const exportCsv = () => { window.location.href = 'settings.php?download=1'; };
            onMounted(() => nextTick(refreshIcons));
            return { store, exportCsv };
        }
    }).mount('#app');
})();
