/**
 * ホーム（TOPページ）の最小 Vue アプリ
 * --------------------------------------------------------------------------
 * 中身（件数・メニュー）はPHPで描画済み。ここは共通ヘッダー（保存インジケーター /
 * CSV出力ボタン）を動かすための最小限。
 */
(function () {
    const { createApp, onMounted, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons } = AppCore;

    createApp({
        setup() {
            // ヘッダーのCSVボタン → 全顧客を出力
            const exportCsv = () => exportClientsCSV(store.clients, '全顧客リスト.csv');
            onMounted(() => nextTick(refreshIcons));
            return { store, exportCsv };
        }
    }).mount('#app');
})();
