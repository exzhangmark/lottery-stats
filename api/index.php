<?php
/**
 * 简易 HTTP 接口（PHP 内置路由 / Nginx fastcgi）
 * 提供前端所需的数据：列表 + 各号码出现次数。
 * 通过 ?action=list&type=ssq 或 ?action=stats&type=ssq 调用。
 */

require_once __DIR__ . '/../worker/lottery_worker.php';  // 复用 db() 等工具函数

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';
$type   = $_GET['type']   ?? '';

if (!in_array($type, ['ssq', 'dlt'])) {
    echo json_encode(['code' => 400, 'msg' => 'type 参数错误']);
    exit;
}

$pdo = db();

if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT issue, red, blue, open_time FROM results WHERE type=? ORDER BY issue DESC LIMIT 200");
    $stmt->execute([$type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['code' => 1, 'data' => $rows]);
    exit;
}

if ($action === 'stats') {
    // 红球总数 / 蓝球总数配置
    $redMax  = $type === 'ssq' ? 33 : 35;
    $blueMax = $type === 'ssq' ? 16 : 12;

    $stmt = $pdo->prepare("SELECT red, blue FROM results WHERE type=?");
    $stmt->execute([$type]);
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

echo json_encode(['code' => 400, 'msg' => 'action 参数错误']);
