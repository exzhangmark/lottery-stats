<?php
/**
 * 简易 HTTP 接口（PHP 内置路由 / Nginx fastcgi）
 * 提供前端所需的数据：列表 + 各号码出现次数。
 * 通过 ?action=list&type=ssq 或 ?action=stats&type=ssq 调用。
 */

require_once __DIR__ . '/../db.php';   // 数据库与意见相关函数（不依赖 Workerman）
require_once __DIR__ . '/../lib.php';  // computeStats / rangeWindow 等统计函数

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$type   = $_GET['type']   ?? '';

// 仅 list / stats 需要 type 参数；订阅类接口不依赖 type
if (in_array($action, ['list', 'stats'], true) && !in_array($type, ['ssq', 'dlt'])) {
    echo json_encode(['code' => 400, 'msg' => 'type 参数错误']);
    exit;
}

$pdo = db();

if ($action === 'list') {
    $range    = $_GET['range'] ?? 'all';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($_GET['pageSize'] ?? 20)));
    $win = rangeWindow($range);
    $where = "WHERE type=?";
    $params = [$type];
    if ($win) {
        $where .= " AND open_time >= ?";
        $params[] = $win['start'];
        if ($win['end']) { $where .= " AND open_time < ?"; $params[] = $win['end']; }
    }
    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM results $where")->execute($params)->fetchColumn();
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare("SELECT issue, red, blue, open_time FROM results $where ORDER BY issue DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [(int)$pageSize, (int)$offset]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['code' => 1, 'data' => $rows, 'page' => $page, 'pageSize' => $pageSize, 'total' => $total]);
    exit;
}

if ($action === 'stats') {
    $range = $_GET['range'] ?? 'all';
    $redMax  = $type === 'ssq' ? 33 : 35;
    $blueMax = $type === 'ssq' ? 16 : 12;
    $stats = computeStats($type, $range);
    echo json_encode([
        'code'      => 1,
        'range'     => $range,
        'redMax'    => $redMax,
        'blueMax'   => $blueMax,
        'redCount'  => $stats['red'],
        'blueCount' => $stats['blue'],
        'redOmit'   => $stats['redOmit'],
        'blueOmit'  => $stats['blueOmit'],
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

// ---------- 推送订阅：提交（POST）----------
if ($action === 'subscribe') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['code' => 405, 'msg' => '请使用 POST 提交']);
        exit;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = trim($_POST['key'] ?? '');
    $scheme = trim($_POST['scheme'] ?? 'cold');
    $range  = trim($_POST['range']  ?? 'year');
    $drawPush = ($_POST['draw_push'] ?? '1') === '1' ? 1 : 0;
    $SCHEMES = ['cold', 'hot', 'mixed', 'omit', 'balance', 'avg', 'repeat', 'lucky', 'flat'];
    $RANGES = ['month', 'year', 'lastyear', '3y', '5y', '10y', 'all'];
    if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $key)) {
        echo json_encode(['code' => 422, 'msg' => '息知 key 格式不正确（应为 8-64 位字母/数字）']);
        exit;
    }
    if (!in_array($scheme, $SCHEMES, true)) $scheme = 'cold';
    if (!in_array($range, $RANGES, true))  $range  = 'year';
    try {
        // 频限：每 IP 每小时最多 10 次订阅（防刷）
        $now = time(); $win = 3600; $max = 10;
        $pdo->prepare("DELETE FROM sub_rate WHERE ts < ?")->execute([$now - $win]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sub_rate WHERE ip=? AND ts>=?");
        $stmt->execute([$ip, $now - $win]);
        if ((int)$stmt->fetchColumn() >= $max) {
            echo json_encode(['code' => 429, 'msg' => '操作过于频繁，请稍后再试']);
            exit;
        }
        $pdo->prepare("INSERT INTO sub_rate (ip, ts) VALUES (?,?)")->execute([$ip, $now]);
        // 幂等：同一 key 重复订阅则更新偏好方案 / 时间范围并恢复状态
        $pdo->prepare("INSERT INTO subscribers (xizhi_key, scheme, range, draw_push, ip, status, created_at) VALUES (?,?,?,?,?,1,datetime('now','localtime')) ON CONFLICT(xizhi_key) DO UPDATE SET scheme=excluded.scheme, range=excluded.range, draw_push=excluded.draw_push, status=1, created_at=datetime('now','localtime')")
            ->execute([$key, $scheme, $range, $drawPush, $ip]);
    } catch (\Throwable $e) {
        // 明确返回 DB 错误，避免连接被 reset 导致浏览器只看到 ERR_CONNECTION_CLOSED
        echo json_encode(['code' => 500, 'msg' => '订阅写入失败：' . $e->getMessage()]);
        exit;
    }
    $msg = $drawPush
        ? '订阅成功，开奖结果与开奖日选号建议将推送到你的息知'
        : '订阅成功，开奖日选号建议将推送到你的息知（已关闭开奖结果推送）';
    echo json_encode(['code' => 1, 'msg' => $msg]);
    exit;
}

// ---------- 推送订阅：取消（GET/POST）----------
if ($action === 'unsubscribe') {
    $key = trim($_GET['key'] ?? ($_POST['key'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $key)) {
        echo json_encode(['code' => 422, 'msg' => 'key 格式不正确']);
        exit;
    }
    $pdo->prepare("UPDATE subscribers SET status=0 WHERE xizhi_key=?")->execute([$key]);
    echo json_encode(['code' => 1, 'msg' => '已取消订阅']);
    exit;
}

// ---------- 推送订阅：当前订阅人数 ----------
if ($action === 'subscriber_count') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE status=1");
    $stmt->execute();
    echo json_encode(['code' => 1, 'count' => (int)$stmt->fetchColumn()]);
    exit;
}

echo json_encode(['code' => 400, 'msg' => 'action 参数错误']);
