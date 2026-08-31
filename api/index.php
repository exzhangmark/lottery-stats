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

// action / type 同时支持 GET 与 POST（savePick 等写接口走 POST 请求体）
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$type   = $_POST['type']   ?? $_GET['type']   ?? '';

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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM results $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
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
    $range  = trim($_POST['range']  ?? 'earliest');
    $drawPush = ($_POST['draw_push'] ?? '1') === '1' ? 1 : 0;
    $SCHEMES = ['cold', 'hot', 'mixed', 'omit', 'balance', 'avg', 'repeat', 'lucky', 'flat'];
    $RANGES = ['earliest', 'month', 'year', 'lastyear', '3y', '5y', '10y', 'all'];
    if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $key)) {
        echo json_encode(['code' => 422, 'msg' => '息知 key 格式不正确（应为 8-64 位字母/数字）']);
        exit;
    }
    if (!in_array($scheme, $SCHEMES, true)) $scheme = 'cold';
    if (!in_array($range, $RANGES, true))  $range  = 'earliest';
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
        $pdo->prepare("INSERT INTO subscribers (xizhi_key, scheme, stat_range, draw_push, ip, status, created_at) VALUES (?,?,?,?,?,1,datetime('now','localtime')) ON CONFLICT(xizhi_key) DO UPDATE SET scheme=excluded.scheme, stat_range=excluded.stat_range, draw_push=excluded.draw_push, status=1, created_at=datetime('now','localtime')")
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

// ---------- 选号存档：保存（POST）----------
if ($action === 'savePick') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['code' => 405, 'msg' => '请使用 POST 提交']);
        exit;
    }
    $type = trim($_POST['type'] ?? '');
    if (!in_array($type, ['ssq', 'dlt'], true)) {
        echo json_encode(['code' => 400, 'msg' => 'type 参数错误']);
        exit;
    }
    $red  = isset($_POST['red'])  ? explode(',', $_POST['red'])  : [];
    $blue = isset($_POST['blue']) ? explode(',', $_POST['blue']) : [];
    $scheme = trim($_POST['scheme'] ?? 'cold');
    $SCHEMES = ['cold', 'hot', 'mixed', 'omit', 'balance', 'avg', 'repeat', 'lucky', 'flat'];
    if (!in_array($scheme, $SCHEMES, true)) $scheme = 'cold';
    try {
        $res = savePick($type, $red, $blue, $scheme);
    } catch (\Throwable $e) {
        echo json_encode(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        exit;
    }
    if ($res['ok']) {
        echo json_encode(['code' => 1, 'issue' => $res['issue'], 'id' => $res['id'], 'msg' => $res['msg']]);
    } else {
        echo json_encode(['code' => 0, 'msg' => $res['msg']]);
    }
    exit;
}

// ---------- 中奖规则查询 ----------
if ($action === 'rules') {
    if (!in_array($type, ['ssq', 'dlt'], true)) {
        echo json_encode(['code' => 400, 'msg' => 'type 参数错误']);
        exit;
    }
    $labels = $type === 'ssq'
        ? ['一等奖', '二等奖', '三等奖', '四等奖', '五等奖', '六等奖']
        : ['一等奖', '二等奖', '三等奖', '四等奖', '五等奖', '六等奖', '七等奖', '八等奖', '九等奖'];
    $rules = prizeRules($type);
    echo json_encode([
        'code'   => 1,
        'type'   => $type,
        'labels' => $labels,
        'rules'  => array_map(function ($r) use ($labels) {
            return [
                'red'        => $r[0],
                'blue'       => $r[1],
                'level'      => $r[2],
                'levelName'  => $labels[$r[2] - 1] ?? ('第' . $r[2] . '奖'),
                'amount'     => $r[3],
                'amountText' => $r[3] > 0 ? number_format($r[3]) . '元' : '浮动奖（视奖池/注数）',
            ];
        }, $rules),
    ]);
    exit;
}

// ---------- 中奖历史：最近 100 条选号存档（含当期开奖号码 + 按奖级汇总）----------
if ($action === 'history') {
    $limit = 100;
    $stmt = $pdo->prepare(
        "SELECT p.type, p.issue, p.red, p.blue, p.scheme, p.prize_level, p.prize_amount, p.status, p.created_at,
                r.red AS res_red, r.blue AS res_blue
         FROM picks p
         LEFT JOIN results r ON r.type=p.type AND r.issue=p.issue
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // 按奖级汇总：一/二等奖金额浮动（记 0），只统计注数；三等奖起显示「注数/共X元」
    $stat = [];
    foreach ($rows as $r) {
        $lv = (int)$r['prize_level'];
        if ($lv < 1) continue;
        if (!isset($stat[$lv])) $stat[$lv] = ['level' => $lv, 'count' => 0, 'amount' => 0];
        $stat[$lv]['count']++;
        $stat[$lv]['amount'] += (int)$r['prize_amount'];
    }
    $stat = array_values($stat);
    usort($stat, fn($a, $b) => $a['level'] <=> $b['level']);
    echo json_encode([
        'code'  => 1,
        'data'  => $rows,
        'stat'  => $stat,
        'count' => count($rows),
    ]);
    exit;
}

// ---------- 我的选号记录：按期号 / 中奖状态筛选 + 分页 + 全量奖级汇总 ----------
if ($action === 'my_picks') {
    $issue  = trim($_GET['issue'] ?? '');
    $status = trim($_GET['status'] ?? 'all');   // all | won | unwon | pending
    $ptype  = trim($_GET['ptype'] ?? '');       // ssq | dlt | 空=全部彩种
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($_GET['pageSize'] ?? 50)));
    $where = [];
    $params = [];
    if ($issue !== '') { $where[] = 'p.issue LIKE ?'; $params[] = '%' . $issue . '%'; }
    if (in_array($ptype, ['ssq', 'dlt'], true)) { $where[] = 'p.type = ?'; $params[] = $ptype; }
    if ($status === 'won')         $where[] = "p.prize_level > 0";
    elseif ($status === 'unwon')   $where[] = "p.status='checked' AND p.prize_level = 0";
    elseif ($status === 'pending') $where[] = "p.status='pending'";
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM picks p $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT p.type, p.issue, p.red, p.blue, p.scheme, p.prize_level, p.prize_amount, p.status, p.created_at,
                r.red AS res_red, r.blue AS res_blue
         FROM picks p
         LEFT JOIN results r ON r.type=p.type AND r.issue=p.issue
         $whereSql
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [(int)$pageSize, (int)(($page - 1) * $pageSize)]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 按奖级汇总（全量筛选结果，不分页），用于顶部统计
    $agg = [];
    $sqlAgg = "SELECT p.prize_level AS lv, COUNT(*) c, COALESCE(SUM(p.prize_amount),0) a FROM picks p $whereSql GROUP BY p.prize_level";
    $stmtAgg = $pdo->prepare($sqlAgg);
    $stmtAgg->execute($params);
    foreach ($stmtAgg->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lv = (int)$row['lv'];
        if ($lv < 1) continue;
        $agg[] = ['level' => $lv, 'count' => (int)$row['c'], 'amount' => (int)$row['a']];
    }
    usort($agg, fn($a, $b) => $a['level'] <=> $b['level']);

    echo json_encode([
        'code'     => 1,
        'data'     => $rows,
        'stat'     => $agg,
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
    ]);
    exit;
}

// ---------- 导出选号记录为 CSV（支持期号/中奖状态/彩种筛选，最多 5000 条）----------
if ($action === 'export_picks') {
    $issue  = trim($_GET['issue'] ?? '');
    $status = trim($_GET['status'] ?? 'all');   // all | won | unwon | pending
    $ptype  = trim($_GET['ptype'] ?? '');       // ssq | dlt | 空=全部彩种
    $limit  = max(1, min(5000, (int)($_GET['limit'] ?? 5000)));

    $where = [];
    $params = [];
    if ($issue !== '') { $where[] = 'p.issue LIKE ?'; $params[] = '%' . $issue . '%'; }
    if (in_array($ptype, ['ssq', 'dlt'], true)) { $where[] = 'p.type = ?'; $params[] = $ptype; }
    if ($status === 'won')         $where[] = "p.prize_level > 0";
    elseif ($status === 'unwon')   $where[] = "p.status='checked' AND p.prize_level = 0";
    elseif ($status === 'pending') $where[] = "p.status='pending'";
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare(
        "SELECT p.type, p.issue, p.red, p.blue, p.scheme, p.prize_level, p.prize_amount, p.status, p.created_at,
                r.red AS res_red, r.blue AS res_blue
         FROM picks p
         LEFT JOIN results r ON r.type=p.type AND r.issue=p.issue
         $whereSql
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ?"
    );
    $stmt->execute(array_merge($params, [$limit]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $typeLabels = ['ssq' => '双色球', 'dlt' => '大乐透'];
    $schemeLabels = [
        'cold' => '冷门预选', 'hot' => '热门预选', 'mixed' => '冷热结合', 'omit' => '遗漏值优先',
        'balance' => '随机均衡', 'avg' => '均值回归', 'repeat' => '历史复刻', 'lucky' => '吉利玄学',
        'flat' => '躺平机选',
    ];
    $statusLabels = ['all' => '全部', 'won' => '已中奖', 'unwon' => '未中奖', 'pending' => '待开奖'];

    // 覆盖前面声明的 JSON 头，改为 CSV 附件下载
    $stamp     = date('Ymd_His');
    $asciiName = 'my_picks_' . (in_array($status, ['won', 'unwon', 'pending'], true) ? $status : 'all') . '_' . $stamp . '.csv';
    $cnName    = '我的选号记录_' . ($statusLabels[$status] ?? '全部') . '_' . $stamp . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($cnName));
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM：让 Excel 正确识别中文
    fputcsv($out, ['彩种', '期号', '选号方案', '我的红球/前区', '我的蓝球/后区', '开奖红球/前区', '开奖蓝球/后区', '状态', '中奖等级', '中奖金额(元)', '生成时间']);
    foreach ($rows as $r) {
        $lv    = (int)$r['prize_level'];
        $maxLv = $r['type'] === 'ssq' ? 6 : 9;
        if ($r['status'] === 'pending') { $stTxt = '待开奖'; $lvTxt = ''; }
        elseif ($lv > 0)                { $stTxt = '已中奖'; $lvTxt = ($lv <= $maxLv ? '第' . $lv . '奖' : '中奖'); }
        else                            { $stTxt = '未中奖'; $lvTxt = ''; }
        // 一/二等奖为浮动奖，金额依赖当期奖池与注数，库中记 0
        $amtTxt = ((int)$r['prize_amount'] > 0)
            ? (string)(int)$r['prize_amount']
            : ($lv >= 1 && $lv <= 2 ? '浮动奖（视奖池/注数）' : '0');
        fputcsv($out, [
            $typeLabels[$r['type']] ?? $r['type'],
            $r['issue'],
            $schemeLabels[$r['scheme']] ?? ($r['scheme'] ?: '—'),
            str_replace(',', '、', (string)$r['red']),
            str_replace(',', '、', (string)$r['blue']),
            $r['res_red']  !== null ? str_replace(',', '、', (string)$r['res_red'])  : '',
            $r['res_blue'] !== null ? str_replace(',', '、', (string)$r['res_blue']) : '',
            $stTxt,
            $lvTxt,
            $amtTxt,
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

echo json_encode(['code' => 400, 'msg' => 'action 参数错误']);
