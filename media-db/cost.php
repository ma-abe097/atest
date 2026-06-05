<?php
declare(strict_types=1);

/**
 * 当月のOpenAI使用額を返すAPI
 * --------------------------------------------------------------------------
 * OpenAIの Costs API（管理者キー sk-admin-... が必要）から当月の使用額(USD)を取得し、
 * 円換算して返す。結果は cost_cache.json に30分キャッシュ（毎回叩かない）。
 *
 * GET cost.php → { enabled, month, usd, jpy, rate, asOf } または { enabled:false } / { error }
 * 設定: config.local.php の OPENAI_ADMIN_KEY（必須）, USD_JPY_RATE（任意・既定155）
 */

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['enabled' => false, 'error' => 'unauthorized']);
    exit;
}

$adminKey = (string) mdb_config('OPENAI_ADMIN_KEY', '');
if ($adminKey === '') {
    echo json_encode(['enabled' => false]);   // 未設定 → 表示しない
    exit;
}

$rate      = (float) mdb_config('USD_JPY_RATE', 155);
$month     = gmdate('Y-m');
$cacheFile = __DIR__ . '/cost_cache.json';
$now       = time();

// キャッシュ（同月・30分以内）。レートは都度反映。
if (is_file($cacheFile)) {
    $c = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($c) && ($c['month'] ?? '') === $month && ($now - (int) ($c['fetchedAt'] ?? 0)) < 1800) {
        $c['rate'] = $rate;
        $c['jpy']  = (int) round(((float) ($c['usd'] ?? 0)) * $rate);
        echo json_encode($c, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 当月1日(UTC)から取得
$start = strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
$url   = 'https://api.openai.com/v1/organization/costs?start_time=' . $start . '&bucket_width=1d&limit=62';
$r     = mdb_http('GET', $url, ['Authorization: Bearer ' . $adminKey]);

if ($r['status'] === 401 || $r['status'] === 403) {
    echo json_encode(['enabled' => true, 'error' => '金額の取得には管理者キー(sk-admin-)が必要です。通常のAPIキーでは取得できません。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($r['status'] !== 200) {
    echo json_encode(['enabled' => true, 'error' => '使用額を取得できませんでした (HTTP ' . $r['status'] . ')。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$d   = json_decode($r['body'], true);
$usd = 0.0;
foreach (($d['data'] ?? []) as $bucket) {
    foreach (($bucket['results'] ?? []) as $res) {
        $usd += (float) ($res['amount']['value'] ?? 0);
    }
}

$out = [
    'enabled'   => true,
    'month'     => $month,
    'usd'       => round($usd, 2),
    'jpy'       => (int) round($usd * $rate),
    'rate'      => $rate,
    'fetchedAt' => $now,
];
@file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
