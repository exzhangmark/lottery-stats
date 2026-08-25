<?php
/**
 * 轻量增量抓取脚本（供定时任务高频调用）
 *
 * 与 worker/backfill.php（历史回填，会读取大量历史 / 全量）不同，本脚本只抓取「最近 N 期」，
 * 用于定时任务每分钟 / 每几分钟执行，避免每次都重新拉取全部历史，效率高、对接口压力小。
 *
 * 用法：
 *   php worker/fetch_recent.php              # 抓取双色球 + 大乐透 各自最近 5 期
 *   php worker/fetch_recent.php --count=10   # 各自最近 10 期
 *   php worker/fetch_recent.php --type=ssq   # 仅双色球
 *
 * 行为：
 *   - 仅使用「分页数据源」(paginate=true)：huiniao、体彩官方等，page 1 即返回最新一期。
 *   - 不使用 cwl 官网 / caipiaodate 这类「一次性返回全量历史」的非分页源，避免读全量历史。
 *   - 抓取第 1 页后，按 open_time 降序取最近 $count 期，写入数据库（按 issue 去重，幂等）。
 *   - 每个彩种只发 1 个 HTTP 请求。
 *   - 若所有分页源都不可用，最后才会回退到任意可用源（含非分页源），同样只取最近 $count 期。
 *
 * 依赖：php_pdo_sqlite 扩展（与前端接口一致）。
 */

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../db.php';

// ---------- 参数 ----------
$count       = 5;
$typeFilter  = 'all';
foreach ($argv as $a) {
    if (preg_match('/^--count=(\d+)$/', $a, $m)) $count = (int)$m[1];
    elseif (preg_match('/^--type=(\w+)$/', $a, $m)) $typeFilter = $m[1];
}
if ($count < 1) $count = 5;
$types = ($typeFilter === 'all') ? ['ssq', 'dlt'] : [$typeFilter];

echo "==============================================\n";
echo " 轻量增量抓取  最近 {$count} 期  types=" . implode(',', $types) . "\n";
echo "==============================================\n\n";

foreach ($types as $type) {
    echo "--- [{$type}] 抓取最近 {$count} 期 ---\n";
    $rows = fetchRecent($type, $count);
    if ($rows === false) {
        echo "  [{$type}] 所有数据源均不可用，跳过\n\n";
        continue;
    }
    $saved = saveRows($type, $rows);
    echo "  抓取 " . count($rows) . " 期，新增 {$saved} 期\n";
    echo "--- [{$type}] 完成 ---\n\n";
}

echo "全部完成。\n";

/**
 * 取某彩种最近 $count 期：
 *   优先用「分页源」，第 1 页即返回最新一期，按 open_time 降序取前 $count 期；
 *   若所有分页源都失败，回退到任意可用源（含非分页源），同样只取前 $count 期（最坏情况会下载较多，仅作兜底）。
 */
function fetchRecent($type, $count)
{
    global $CONFIG;

    // 第一遍：仅分页源（轻量）
    foreach ($CONFIG['apis'] as $api) {
        if (empty($api[$type])) continue;                 // 不含该彩种
        if (empty($api['paginate'])) continue;            // 跳过非分页源（会返回全量历史）
        $rows = fetchPageRows($type, $api, 1, max($count, $CONFIG['page_size']));
        if ($rows === false || empty($rows)) {
            echo "  [{$type}] 分页源 {$api['name']} 第 1 页读取失败，尝试下一个\n";
            continue;
        }
        echo "  [{$type}] 使用分页源：{$api['name']}\n";
        return sliceLatest($rows, $count);
    }

    // 兜底：任意可用源（含非分页源），只取最近 $count 期
    foreach ($CONFIG['apis'] as $api) {
        if (empty($api[$type])) continue;
        $rows = fetchPageRows($type, $api, 1, max($count, $CONFIG['page_size']));
        if ($rows === false || empty($rows)) continue;
        echo "  [{$type}] 分页源均不可用，回退源：{$api['name']}\n";
        return sliceLatest($rows, $count);
    }
    return false;
}

// 按 open_time 降序，取最近 $count 期
function sliceLatest($rows, $count)
{
    usort($rows, function ($a, $b) {
        $ta = isset($a['open_time']) && $a['open_time'] ? strtotime($a['open_time']) : 0;
        $tb = isset($b['open_time']) && $b['open_time'] ? strtotime($b['open_time']) : 0;
        return $tb <=> $ta;
    });
    return array_slice($rows, 0, $count);
}
