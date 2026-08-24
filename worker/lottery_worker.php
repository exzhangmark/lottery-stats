<?php
/**
 * 彩票开奖数据抓取 Worker（Workerman 定时器）
 * 双色球（ssq）与大乐透（dlt）从两个免费 API 读取，失败则 1 小时后重试。
 * 依赖：workerman/workerman  (composer require workerman/workerman)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;
use Workerman\Timer;

// ---------- 配置 ----------
$CONFIG = [
    // 两个免费 API（互为备份）
    'apis' => [
        'primary' => [
            'name' => 'huiniao',
            'ssq'  => 'http://api.huiniao.top/interface/home/lotteryHistory?type=ssq&page=1&limit=20',
            'dlt'  => 'http://api.huiniao.top/interface/home/lotteryHistory?type=dlt&page=1&limit=20',
            'parse' => 'parseHuiniao',
        ],
        'backup' => [
            'name' => 'caipiaodate',
            'ssq'  => 'https://www.caipiaodate.com/foregroundPCController/void.do?code=ssq&rows=20&format=json',
            'dlt'  => 'https://www.caipiaodate.com/foregroundPCController/void.do?code=dlt&rows=20&format=json',
            'parse' => 'parseCaipiaodate',
        ],
    ],
    'retry_seconds' => 3600,   // 读取失败 1 小时后重试
    'loop_seconds'  => 600,    // 正常轮询间隔 10 分钟
];

// ---------- 数据库 ----------
function db()
{
    static $pdo;
    if ($pdo) return $pdo;
    $dbFile = __DIR__ . '/../data/lottery.sqlite';
    $dir = dirname($dbFile);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,            -- ssq / dlt
        issue TEXT NOT NULL,           -- 期号
        red TEXT NOT NULL,             -- 红球，逗号分隔
        blue TEXT NOT NULL,            -- 蓝球，逗号分隔
        open_time TEXT,                -- 开奖时间
        fetch_time TEXT DEFAULT (datetime('now','localtime')),
        UNIQUE(type, issue)
    )");
    // 用户意见表
    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nickname TEXT,
        content TEXT NOT NULL,
        ip TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");
    return $pdo;
}

// ---------- 意见防攻击：速率限制（基于 IP + 时间窗）----------
// 使用 SQLite 内存表记录提交时间戳，限制每 IP 每 60 秒最多 3 条。
function feedbackRateLimited($ip)
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_rate (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        ts INTEGER NOT NULL
    )");
    $now  = time();
    $win  = 60;   // 时间窗（秒）
    $max  = 3;    // 时间窗内最大提交数
    // 清理过期记录
    $pdo->prepare("DELETE FROM feedback_rate WHERE ts < ?")->execute([$now - $win]);
    // 统计本 IP 在时间窗内的提交数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback_rate WHERE ip=? AND ts>=?");
    $stmt->execute([$ip, $now - $win]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt >= $max) return true;
    // 记录本次
    $pdo->prepare("INSERT INTO feedback_rate (ip, ts) VALUES (?,?)")->execute([$ip, $now]);
    return false;
}

// 内容校验与清洗：防 XSS / 注入 / 垃圾
function sanitizeFeedback(&$nickname, &$content)
{
    $errors = [];
    // 去除首尾空白
    $nickname = isset($nickname) ? trim($nickname) : '';
    $content  = trim($content ?? '');
    // 内容必填、长度限制
    $maxContent = 500;
    $maxNick    = 30;
    if ($content === '') {
        $errors[] = '内容不能为空';
    } elseif (mb_strlen($content, 'UTF-8') > $maxContent) {
        $errors[] = "内容不能超过 {$maxContent} 字";
    }
    if (mb_strlen($nickname, 'UTF-8') > $maxNick) {
        $nickname = mb_substr($nickname, 0, $maxNick, 'UTF-8');
    }
    // 只允许常见可见字符，过滤控制字符；保留中文/英文/数字/标点
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content);
    // 限制连续重复字符（防止刷屏）
    $content = preg_replace('/(.)\1{20,}/u', '$1$1$1', $content);
    return $errors;
}

// ---------- 抓取 ----------
function httpGet($url, $timeout = 8)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LotteryFetcher/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code != 200) return false;
    return $body;
}

function decodeJson($body)
{
    $data = json_decode($body, true);
    return is_array($data) ? $data : false;
}

// 解析 huiniao 返回
function parseHuiniao($json, $type)
{
    if (!isset($json['code']) || $json['code'] != 1) return false;
    $list = $json['data']['data']['list'] ?? [];
    if (empty($list)) return false;
    $rows = [];
    foreach ($list as $item) {
        if ($type === 'ssq') {
            $red  = [$item['one'], $item['two'], $item['three'], $item['four'], $item['five'], $item['six']];
            $blue = [$item['seven']];
        } else { // dlt
            $red  = [$item['one'], $item['two'], $item['three'], $item['four'], $item['five']];
            $blue = [$item['six'], $item['seven']];
        }
        $rows[] = [
            'issue'     => $item['code'],
            'red'       => implode(',', array_map('intval', $red)),
            'blue'      => implode(',', array_map('intval', $blue)),
            'open_time' => $item['open_time'] ?? null,
        ];
    }
    return $rows;
}

// 解析 caipiaodate 返回（常见结构：直接数组或 data 数组）
function parseCaipiaodate($json, $type)
{
    $list = $json['data'] ?? $json;
    if (!is_array($list)) return false;
    // 若是关联数组（单条），包装为列表
    if (isset($list['expect']) || isset($list['code'])) $list = [$list];
    if (empty($list)) return false;
    $rows = [];
    foreach ($list as $item) {
        $issue = $item['expect'] ?? $item['code'] ?? null;
        $opencode = $item['opencode'] ?? $item['openCode'] ?? $item['number'] ?? '';
        if (!$issue || !$opencode) continue;
        // 开奖号码格式一般为 "01,02,03,04,05,06+07"
        $parts = explode('+', str_replace('|', '+', $opencode));
        $red  = explode(',', $parts[0]);
        $blue = isset($parts[1]) ? explode(',', $parts[1]) : [];
        $red  = array_map('intval', array_filter($red));
        $blue = array_map('intval', array_filter($blue));
        if (empty($red)) continue;
        $rows[] = [
            'issue'     => $issue,
            'red'       => implode(',', $red),
            'blue'      => implode(',', $blue),
            'open_time' => $item['opentime'] ?? $item['time'] ?? $item['openTime'] ?? null,
        ];
    }
    return $rows;
}

// 写入数据库（忽略重复期号）
function saveRows($type, $rows)
{
    $pdo = db();
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO results (type, issue, red, blue, open_time) VALUES (?,?,?,?,?)");
    $count = 0;
    foreach ($rows as $r) {
        $stmt->execute([$type, $r['issue'], $r['red'], $r['blue'], $r['open_time']]);
        $count += $stmt->rowCount();
    }
    return $count;
}

// 抓取单个彩种（遍历 API 列表，任一成功即可）
function fetchLottery($type)
{
    global $CONFIG;
    foreach ($CONFIG['apis'] as $api) {
        $url = $api[$type];
        $body = httpGet($url);
        if ($body === false) {
            echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 请求失败\n";
            continue;
        }
        $json = decodeJson($body);
        if ($json === false) {
            echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} JSON 解析失败\n";
            continue;
        }
        $rows = call_user_func($api['parse'], $json, $type);
        if (empty($rows)) {
            echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 数据为空\n";
            continue;
        }
        $saved = saveRows($type, $rows);
        echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 成功，抓取 " . count($rows) . " 期，新增 {$saved} 期\n";
        return true;
    }
    return false;
}

// 主任务：任一失败则 1 小时后重试
function runFetch()
{
    $okSsq = fetchLottery('ssq');
    $okDlt = fetchLottery('dlt');

    if (!$okSsq || !$okDlt) {
        echo date('Y-m-d H:i:s') . " 部分彩种抓取失败，1 小时后重试\n";
        Timer::add($CONFIG['retry_seconds'], function () {
            runFetch();
        }, [], false);
    }
}

// ---------- 启动 ----------
$worker = new Worker();
$worker->name = 'LotteryFetcher';
$worker->onWorkerStart = function () {
    global $CONFIG;
    echo date('Y-m-d H:i:s') . " LotteryFetcher 启动\n";
    runFetch();                                  // 启动时立即抓取一次
    Timer::add($CONFIG['loop_seconds'], 'runFetch');  // 之后每 10 分钟轮询
};

Worker::runAll();
