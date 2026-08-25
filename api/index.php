<?php
/**
 * 简易 HTTP 接口（PHP 内置路由 / Nginx fastcgi）
 * 提供前端所需的数据：列表 + 各号码出现次数。
 * 通过 ?action=list&type=ssq 或 ?action=stats&type=ssq 调用。
 */

require_once __DIR__ . '/../db.php';  // 数据库与意见相关函数（不依赖 Workerman）

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$type   = $_GET['type']   ?? '';
$range  = $_GET['range']  ?? 'all';
$allowedRanges = ['all', 'month', 'year', 'lastyear', '3y', '5y', '10y'];
if (!in_array($range, $allowedRanges)) {
    $range = 'all';
}

if (!in_array($type, ['ssq', 'dlt'])) {
    echo json_encode(['code' => 400, 'msg' => 'type 参数错误']);
    exit;
}

// 根据时间范围计算出 open_time 的过滤窗口（[start, end)，end 为 null 表示到当前为止）
function rangeWindow($r)
{
    $now = new DateTime();
    switch ($r) {
        case 'month':    return [$now->format('Y-m-01'), null];
        case 'year':     return [$now->format('Y') . '-01-01', null];
        case 'lastyear':
            $y = (int)$now->format('Y') - 1;
            return [$y . '-01-01', ($y + 1) . '-01-01'];
        case '3y':  return [(clone $now)->modify('-3 years')->format('Y-m-d'), null];
        case '5y':  return [(clone $now)->modify('-5 years')->format('Y-m-d'), null];
        case '10y': return [(clone $now)->modify('-10 years')->format('Y-m-d'), null];
        case 'all':
        default:    return [null, null];
    }
}

$pdo = db();

if ($action === 'list') {
    list($start, $end) = rangeWindow($range);

    // 组合过滤条件 + 占位符（彩种 + 时间范围）
    $cond  = "type = ?";
    $binds = [$type];
    if ($start !== null) { $cond .= " AND date(open_time) >= date(?)"; $binds[] = $start; }
    if ($end   !== null) { $cond .= " AND date(open_time) < date(?)";  $binds[] = $end;   }

    // 分页参数：每页 10/20/50/100，默认 20
    $pageSize = (int)($_GET['pageSize'] ?? 20);
    if (!in_array($pageSize, [10, 20, 50, 100], true)) $pageSize = 20;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $pageSize;

    // 总数（用于前端分页）
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE {$cond}");
    $cStmt->execute($binds);
    $total = (int)$cStmt->fetchColumn();

    // 本页数据（LIMIT/OFFSET 用 PARAM_INT 绑定，避免字符串被 SQLite 拒绝）
    $sql = "SELECT issue, red, blue, open_time FROM results WHERE {$cond} ORDER BY issue DESC LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $p = 1;
    foreach ($binds as $b) { $stmt->bindValue($p++, $b, PDO::PARAM_STR); }
    $stmt->bindValue($p++, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue($p++, $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'code'     => 1,
        'data'     => $rows,
        'page'     => $page,
        'pageSize' => $pageSize,
        'total'    => $total,
    ]);
    exit;
}

if ($action === 'stats') {
    // 红球总数 / 蓝球总数配置
    $redMax  = $type === 'ssq' ? 33 : 35;
    $blueMax = $type === 'ssq' ? 16 : 12;

    list($start, $end) = rangeWindow($range);
    $sql = "SELECT red, blue FROM results WHERE type=?";
    $params = [$type];
    if ($start !== null) { $sql .= " AND date(open_time) >= date(?)"; $params[] = $start; }
    if ($end   !== null) { $sql .= " AND date(open_time) < date(?)";  $params[] = $end;   }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $redCount  = array_fill(1, $redMax, 0);
    $blueCount = array_fill(1, $blueMax, 0);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach (explode(',', $r['red']) as $n) {
            $n = (int)$n;
            if ($n >= 1 && $n <= $redMax) $redCount[$n]++;
        }
        foreach (explode(',', $r['blue']) as $n) {
            $n = (int)$n;
            if ($n >= 1 && $n <= $blueMax) $blueCount[$n]++;
        }
    }
    echo json_encode([
        'code'      => 1,
        'redMax'    => $redMax,
        'blueMax'   => $blueMax,
        'redCount'  => $redCount,
        'blueCount' => $blueCount,
    ]);
    exit;
}

// ---------- 意见：列表 ----------
if ($action === 'feedback_list') {
    $stmt = $pdo->prepare("SELECT id, nickname, content, created_at FROM feedback ORDER BY id DESC LIMIT 100");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['code' => 1, 'data' => $rows]);
    exit;
}

// ---------- 意见：提交（POST）----------
if ($action === 'feedback_add') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['code' => 405, 'msg' => '请使用 POST 提交']);
        exit;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // 1) 速率限制：每 IP 60 秒内最多 3 条
    if (feedbackRateLimited($ip)) {
        echo json_encode(['code' => 429, 'msg' => '提交过于频繁，请稍后再试']);
        exit;
    }
    // 2) 内容校验与清洗
    $nickname = $_POST['nickname'] ?? '';
    $content  = $_POST['content']  ?? '';
    $errors   = sanitizeFeedback($nickname, $content);
    if ($errors) {
        echo json_encode(['code' => 422, 'msg' => implode('；', $errors)]);
        exit;
    }
    // 3) 参数化写入（防 SQL 注入）
    $stmt = $pdo->prepare("INSERT INTO feedback (nickname, content, ip) VALUES (?,?,?)");
    $stmt->execute([$nickname ?: '匿名', $content, $ip]);
    echo json_encode(['code' => 1, 'msg' => '感谢反馈，已入库']);
    exit;
}

echo json_encode(['code' => 400, 'msg' => 'action 参数错误']);
