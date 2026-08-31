<?php
/**
 * 历史开奖数据回填脚本（一次性 / 按需运行）
 *
 * 用法：
 *   php worker/backfill.php                      # 回填 2026-01-01 至今（双色球 + 大乐透）
 *   php worker/backfill.php --since=2025-01-01   # 自定义起始日期
 *   php worker/backfill.php --type=ssq           # 只回填双色球
 *   php worker/backfill.php --source=huiniao     # 强制只用指定数据源（调试用）
 *   php worker/backfill.php --all                # 全量回填（自 1995 年起的全部历史）
 *
 * 行为：
 *   - 对每个彩种，优先选用「第一个能返回数据」的数据源，并沿该源分页向后翻（最新→最早）。
 *   - 每翻一页之间随机休眠 3~5 秒，模拟正常访问速度，避免被接口限流。
 *   - 仅写入 open_time >= 起始日期 的开奖记录（无时间字段的记录一律保留）。
 *   - 按 (type, issue) 去重，重复期号自动忽略。
 *   - 当某页「最新一期」已早于起始日期时停止该彩种回填。
 *
 * 依赖：php_pdo_sqlite 扩展（与前端接口一致）。
 */

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../db.php';

// ---------- 解析命令行参数 ----------
$since       = '2026-01-01';
$typeFilter  = 'all';
$forceSource = null;
foreach ($argv as $a) {
    if (preg_match('/^--since=(.+)$/', $a, $m))   $since = trim($m[1]);
    elseif (preg_match('/^--type=(\w+)$/', $a, $m)) $typeFilter = $m[1];
    elseif (preg_match('/^--source=(.+)$/', $a, $m)) $forceSource = trim($m[1]);
    elseif ($a === '--all') $since = '1995-01-01';   // 全量历史回填（双色球 2003 起 / 大乐透 2007 起，1995 已覆盖）
}

$sinceTs = strtotime($since);
if ($sinceTs === false) {
    fwrite(STDERR, "无效的 --since 日期：{$since}（请使用 YYYY-MM-DD）\n");
    exit(1);
}
$types = ($typeFilter === 'all') ? ['ssq', 'dlt'] : [$typeFilter];

$pageSize = $CONFIG['page_size'];
$MAX_PAGES = 400;   // 单彩种安全上限，防止异常死循环

echo "==============================================\n";
echo " 历史开奖回填  since={$since}  types=" . implode(',', $types) . "\n";
echo "==============================================\n\n";

foreach ($types as $type) {
    echo "--- 开始回填 [{$type}]（自 {$since} 起）---\n";

    // 选定数据源
    $chosen = null;
    $chosenName = '';
    if ($forceSource !== null) {
        foreach ($CONFIG['apis'] as $api) {
            if ($api['name'] === $forceSource) { $chosen = $api; $chosenName = $api['name']; break; }
        }
        if (!$chosen) { echo "未找到数据源：{$forceSource}，跳过 [{$type}]\n\n"; continue; }
        echo "已强制指定数据源：{$chosenName}\n";
    } else {
        foreach ($CONFIG['apis'] as $api) {
            if (empty($api[$type])) { echo "  {$api['name']} 不含 [{$type}]，跳过\n"; continue; }
            $rows = fetchPageRows($type, $api, 1, $pageSize);
            if ($rows !== false && count($rows) > 0) {
                $chosen = $api; $chosenName = $api['name'];
                echo "  选定数据源：{$chosenName}\n";
                break;
            }
            echo "  {$api['name']} 暂不可用或无数据，尝试下一个\n";
        }
    }
    if (!$chosen) { echo "[{$type}] 无可用数据源，跳过\n\n"; continue; }

    // 不分页的源：仅抓取最新一页
    if (empty($chosen['paginate'])) {
        $rows = fetchPageRows($type, $chosen, 1, $pageSize);
        if ($rows !== false) {
            $eligible = filterRows($rows, $sinceTs);
            $saved = saveRows($type, $eligible);
            echo "  [{$chosenName}] 非分页源，本页 " . count($rows) . " 期，符合窗口 " . count($eligible) . " 期，新增 {$saved} 期\n";
        } else {
            echo "  [{$chosenName}] 读取失败\n";
        }
        echo "--- [{$type}] 回填结束 ---\n\n";
        continue;
    }

    // 分页回填
    $page = 1;
    $totalSaved = 0;
    while ($page <= $MAX_PAGES) {
        if ($page > 1) {
            $sleep = rand(3, 5);   // 模拟正常访问速度，避免限流
            echo "  （休眠 {$sleep}s 避免限流）\n";
            sleep($sleep);
        }
        $rows = fetchPageRows($type, $chosen, $page, $pageSize);
        if ($rows === false) {
            echo "  第 {$page} 页读取失败或为空，停止 [{$type}]\n";
            break;
        }
        $eligible = filterRows($rows, $sinceTs);
        $saved = saveRows($type, $eligible);
        $totalSaved += $saved;
        echo "  第 {$page} 页：本页 " . count($rows) . " 期，符合窗口 " . count($eligible) . " 期，新增 {$saved} 期\n";

        // 停止条件：本页最新一期已早于起始日期
        $maxTs = maxOpenTime($rows);
        if ($maxTs !== null && $maxTs < $sinceTs) {
            echo "  本页最新一期已早于 {$since}，回填结束\n";
            break;
        }
        $page++;
    }
    echo "--- [{$type}] 回填完成，累计新增 {$totalSaved} 期 ---\n\n";
}

// 回填完成后，补核对所有「尚未核对且对应期号已开奖」的选号存档
$n = checkAllPendingWins();
if ($n > 0) echo "已补核对历史选号存档 {$n} 条中奖结果。\n";

echo "全部完成。\n";

// ---------- 辅助函数 ----------
// 仅保留 open_time >= $sinceTs 的记录（无时间字段则一律保留）
function filterRows($rows, $sinceTs)
{
    $out = [];
    foreach ($rows as $r) {
        $t = isset($r['open_time']) && $r['open_time'] !== '' ? strtotime($r['open_time']) : false;
        if ($t === false || $t >= $sinceTs) $out[] = $r;
    }
    return $out;
}

// 取一组记录中最大的开奖时间（无时间则返回 null）
function maxOpenTime($rows)
{
    $mx = null;
    foreach ($rows as $r) {
        if (empty($r['open_time'])) continue;
        $t = strtotime($r['open_time']);
        if ($t !== false && ($mx === null || $t > $mx)) $mx = $t;
    }
    return $mx;
}
