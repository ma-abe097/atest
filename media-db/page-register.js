/**
 * データ登録・読込 ページ
 * --------------------------------------------------------------------------
 * 顧客を1件ずつ登録するフォームと、Excel/CSVテキストの一括インポート。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons } = AppCore;

    const todayStr = new Date().toISOString().slice(0, 10);

    /** ランダムなID生成（衝突しにくい接頭辞付き） */
    const genId = (prefix) => prefix + Date.now() + Math.floor(Math.random() * 100000);

    /** 媒体名から既存IDを返す。無ければ新規作成してIDを返す。 */
    const findOrCreateMedia = (name, domain = '-') => {
        const n = (name || '').trim();
        if (!n) return null;
        const found = store.media.find(m => m.name.toLowerCase() === n.toLowerCase());
        if (found) return found.id;
        const id = genId('m');
        store.media.push({ id, name: n, domain });
        return id;
    };

    createApp({
        setup() {
            const mediaList = computed(() => store.media);

            const newClient = ref({ name: '', industry: '', ourService: '', orderDate: todayStr, address: '', sourceMediaId: '', usedMediaIds: [] });
            const newClientManualFlags = ref('');
            const successMessage = ref('');

            const importText = ref('');
            const importMessage = ref('');
            const importError = ref(false);

            // チェックボックスのリストから媒体を削除（顧客側の参照も除去）
            const deleteMedia = (index) => {
                const media = store.media[index];
                if (!media) return;
                const deletedId = media.id;
                store.media.splice(index, 1);
                store.clients.forEach(client => {
                    client.usedMediaIds = (client.usedMediaIds || []).filter(id => id !== deletedId);
                });
                successMessage.value = `フラグ「${media.name}」を削除しました。`;
                setTimeout(() => { successMessage.value = ''; }, 3000);
            };

            const registerSingleClient = () => {
                const finalMediaIds = [...newClient.value.usedMediaIds];

                // 手動入力フラグ（カンマ/読点区切り）を媒体マスタへ反映
                if (newClientManualFlags.value.trim() !== '') {
                    const manualFlags = newClientManualFlags.value.split(/[,、]+/).map(s => s.trim()).filter(Boolean);
                    manualFlags.forEach(flagName => {
                        const id = findOrCreateMedia(flagName);
                        if (id && !finalMediaIds.includes(id)) finalMediaIds.push(id);
                    });
                }

                store.clients.push({
                    id: genId('c'),
                    name: newClient.value.name,
                    industry: newClient.value.industry,
                    ourService: newClient.value.ourService,
                    orderDate: newClient.value.orderDate,
                    address: newClient.value.address,
                    sourceMediaId: newClient.value.sourceMediaId,
                    usedMediaIds: finalMediaIds,
                });

                newClient.value = { name: '', industry: '', ourService: '', orderDate: todayStr, address: '', sourceMediaId: '', usedMediaIds: [] };
                newClientManualFlags.value = '';
                successMessage.value = '顧客データを1件登録しました！';
                setTimeout(() => { successMessage.value = ''; }, 3000);
            };

            const processImport = () => {
                if (!importText.value.trim()) {
                    importError.value = true;
                    importMessage.value = 'データが入力されていません。';
                    return;
                }

                const lines = importText.value.split('\n');
                let successCount = 0;
                let skipCount = 0;

                lines.forEach(line => {
                    if (!line.trim()) return;
                    const delimiter = line.includes('\t') ? '\t' : ',';
                    const columns = line.split(delimiter).map(c => c.trim());
                    if (columns.length < 1 || !columns[0]) { skipCount++; return; }

                    // 列: 顧客名, 業界, 自社サービス, 受注日, 住所, リスト元媒体, 利用媒体(最後・カンマ区切り)
                    const name = columns[0];
                    const industry = columns[1] || '';
                    const ourService = columns[2] || '';
                    const orderDate = columns[3] || todayStr;
                    const address = columns[4] || '';
                    const sourceMediaName = columns[5] || '';
                    // 利用媒体は最後の列。カンマ区切りで内部にカンマを含むため、6列目以降を結合して扱う。
                    const mediaNamesStr = columns.slice(6).join(delimiter);

                    const sourceMediaId = sourceMediaName ? findOrCreateMedia(sourceMediaName) : '';

                    const mediaNames = mediaNamesStr.split(/[,、\s]+/).filter(Boolean);
                    const mediaIds = [];
                    mediaNames.forEach(mName => {
                        const found = store.media.find(m => m.name.toLowerCase() === mName.toLowerCase() || mName.includes(m.name));
                        if (found) {
                            mediaIds.push(found.id);
                        } else {
                            const id = genId('m');
                            store.media.push({ id, name: mName, domain: 'unknown' });
                            mediaIds.push(id);
                        }
                    });

                    store.clients.push({
                        id: genId('c'),
                        name, industry, ourService, orderDate, address, sourceMediaId,
                        usedMediaIds: mediaIds,
                    });
                    successCount++;
                });

                if (successCount > 0) {
                    importError.value = false;
                    importMessage.value = `${successCount}件のデータを正常にインポートしました！(スキップ: ${skipCount}件)`;
                    importText.value = '';
                } else {
                    importError.value = true;
                    importMessage.value = '有効なデータが見つかりませんでした。フォーマットを確認してください。';
                }
                setTimeout(() => { importMessage.value = ''; }, 5000);
            };

            // ヘッダーのCSVボタン → 全顧客を出力
            const exportCsv = () => exportClientsCSV(store.clients, '全顧客リスト.csv');

            // 媒体リストが変わるとゴミ箱アイコンが増減するので再描画
            watch(() => store.media.length, () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, mediaList,
                newClient, newClientManualFlags, successMessage, registerSingleClient, deleteMedia,
                importText, importMessage, importError, processImport,
                exportCsv,
            };
        }
    }).mount('#app');
})();
