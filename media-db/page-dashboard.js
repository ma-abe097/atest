/**
 * 受注一覧・ランキング ページ
 * --------------------------------------------------------------------------
 * 期間で受注顧客を絞り込み、一覧と「併用媒体ランキング」を表示する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, getMediaDetails, calculateRanking, exportClientsCSV, refreshIcons } = AppCore;

    createApp({
        setup() {
            // 初期表示は「前日」分（日々の受注チェック用）。空なら「全期間にリセット」で全件表示。
            const d = new Date();
            d.setDate(d.getDate() - 1);
            const yesterday = d.toISOString().slice(0, 10);

            const filterStartDate = ref(yesterday);
            const filterEndDate   = ref(yesterday);

            const filteredClientsByDate = computed(() => {
                return store.clients.filter(c => {
                    if (!filterStartDate.value && !filterEndDate.value) return true;
                    const orderDate = new Date(c.orderDate);
                    let afterStart = true, beforeEnd = true;
                    if (filterStartDate.value) afterStart = orderDate >= new Date(filterStartDate.value);
                    if (filterEndDate.value)   beforeEnd  = orderDate <= new Date(filterEndDate.value);
                    return afterStart && beforeEnd;
                }).sort((a, b) => new Date(b.orderDate) - new Date(a.orderDate));
            });

            const dateRangeText = computed(() => {
                if (!filterStartDate.value && !filterEndDate.value) return '全期間';
                if (filterStartDate.value && filterEndDate.value)   return `${filterStartDate.value} 〜 ${filterEndDate.value}`;
                if (filterStartDate.value) return `${filterStartDate.value} 以降`;
                if (filterEndDate.value)   return `${filterEndDate.value} 以前`;
                return '';
            });

            const periodMediaRanking = computed(() => calculateRanking(filteredClientsByDate.value));

            // 対象顧客の中に「他に利用している媒体」データを持つ人がいるか（空表示の出し分け用）
            const hasOtherMediaData = computed(() =>
                filteredClientsByDate.value.some(c => (c.usedMediaIds || []).length > 0)
            );

            const resetDateFilter = () => {
                filterStartDate.value = '';
                filterEndDate.value = '';
            };

            const deleteClient = (id) => {
                if (confirm('この顧客データを削除してもよろしいですか？')) {
                    store.clients = store.clients.filter(c => c.id !== id);
                }
            };

            // 受注リスト元（獲得元の媒体）
            const sourceMedia = (client) => store.media.find(m => m.id === client.sourceMediaId) || null;

            // 媒体が実在ドメインを持つか（手動追加の '-' や 'unknown' は除外）
            const hasDomain = (media) => {
                const d = (media.domain || '').trim();
                return d !== '' && d !== '-' && d !== 'unknown' && d.includes('.');
            };
            const siteUrl = (media) => 'https://' + (media.domain || '').trim().replace(/^https?:\/\//, '');

            // 住所から番地（最初の数字以降）を落として「町名まで」にする
            const strippedAddress = (client) => {
                const a = (client.address || '').trim();
                if (!a) return '';
                const cut = a.replace(/[0-9０-９].*$/, '').replace(/[\s　]+$/, '').trim();
                return cut || a;
            };

            // 会社名＋住所(番地抜き)で、その媒体サイト内をGoogle検索するURL
            const searchUrl = (client, media) => {
                const parts = ['"' + (client.name || '') + '"'];
                const addr = strippedAddress(client);
                if (addr) parts.push('"' + addr + '"');
                parts.push(hasDomain(media) ? 'site:' + media.domain.trim().replace(/^https?:\/\//, '') : media.name);
                return 'https://www.google.com/search?q=' + encodeURIComponent(parts.join(' '));
            };

            const exportCsv = () => {
                const label = (dateRangeText.value || '全期間').replace(/ /g, '');
                exportClientsCSV(filteredClientsByDate.value, `受注リスト_${label}.csv`);
            };

            // リスト内容が変わったらアイコンを再描画
            watch(filteredClientsByDate, () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store,
                filterStartDate, filterEndDate, resetDateFilter,
                filteredClientsByDate, dateRangeText, periodMediaRanking, hasOtherMediaData,
                getMediaDetails, deleteClient, exportCsv,
                sourceMedia, hasDomain, siteUrl, strippedAddress, searchUrl,
            };
        }
    }).mount('#app');
})();
