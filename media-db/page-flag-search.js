/**
 * フラグ(媒体)別 逆引き検索 ページ
 * --------------------------------------------------------------------------
 * リスト元（受注の獲得元媒体）を1つ選ぶと、
 * 「そのリスト元から受注した顧客」と、その顧客たちが
 * 「他に利用している媒体」の重複ランキングを表示する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, calculateRanking, exportClientsCSV, refreshIcons } = AppCore;

    createApp({
        setup() {
            const selectedMediaId = ref('');

            // ドロップダウンに出すのは「実際にリスト元として使われている媒体」だけ
            const sourceMediaList = computed(() => {
                const ids = new Set(store.clients.map(c => c.sourceMediaId).filter(Boolean));
                return store.media.filter(m => ids.has(m.id));
            });

            const selectedMediaName = computed(() => {
                const m = store.media.find(x => x.id === selectedMediaId.value);
                return m ? m.name : '';
            });

            // 選んだリスト元から受注した顧客
            const filteredClientsByFlag = computed(() => {
                if (!selectedMediaId.value) return [];
                return store.clients.filter(c => c.sourceMediaId === selectedMediaId.value);
            });

            // その顧客たちが「他に利用している媒体」のランキング
            const flaggedMediaRanking = computed(() => calculateRanking(filteredClientsByFlag.value));

            // 対象顧客の中に「他媒体」データを持つ人がいるか（空表示の出し分け用）
            const hasOtherMediaData = computed(() =>
                filteredClientsByFlag.value.some(c => (c.usedMediaIds || []).length > 0)
            );

            const exportCsv = () => {
                if (!selectedMediaId.value) {
                    alert('先にリスト元を選択してください。');
                    return;
                }
                exportClientsCSV(filteredClientsByFlag.value, `逆引きリスト_${selectedMediaName.value}.csv`);
            };

            watch([selectedMediaId, filteredClientsByFlag], () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, sourceMediaList,
                selectedMediaId, selectedMediaName,
                filteredClientsByFlag, flaggedMediaRanking, hasOtherMediaData,
                exportCsv,
            };
        }
    }).mount('#app');
})();
