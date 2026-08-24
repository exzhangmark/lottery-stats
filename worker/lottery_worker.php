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
    // 数据源（按数组顺序作为优先级：任一成功即采用，全部失败才 1 小时后重试）
    // 主源：福彩官网 cwl.gov.cn（仅覆盖福利彩票，如双色球；不含体彩大乐透）
    // 备用：huiniao、caipiaodate（两者均覆盖双色球与大乐透，负责兜底）
    'apis' => [
        'official' => [
            'name' => 'cwl.gov.cn',
            'ssq'  => 'https://www.cwl.gov.cn/cwl_admin/front/cwlkj/search/kjxx/findDrawNotice?name=ssq&currentPage=1&pageSize=20',
            'dlt'  => null,   // 福彩官网不含体彩大乐透，自动跳过，回退备用源
            'parse' => 'parseCwl',
            'headers' => ['Referer: http://www.cwl.gov.cn/'],
        ],
        'backup1' => [
            'name' => 'huiniao',
            'ssq'  => 'http://api.huiniao.top/interface/home/lotteryHistory?type=ssq&page=1&limit=20',
            'dlt'  => 'http://api.huiniao.top/interface/home/lotteryHistory?type=dlt&page=1&limit=20',
            'parse' => 'parseHuiniao',
        ],
        'backup2' => [
            'name' => 'caipiaodate',
            'ssq'  => 'https://www.caipiaodate.com/foregroundPCController/void.do?code=ssq&rows=20&format=json',
            'dlt'  => 'https://www.caipiaodate.com/foregroundPCController/void.do?code=dlt&rows=20&format=json',
            'parse' => 'parseCaipiaodate',
        ],
    ],
    'retry_seconds' => 3600,   // 读取失败 1 小时后重试
    'loop_seconds'  => 600,    // 正常轮询间隔 10 分钟
];

// ---------- 数据库 / 意见相关函数（独立文件，不依赖 Workerman）----------
require_once __DIR__ . '/../db.php';

// ---------- 抓取 ----------
function httpGet($url, $timeout = 8, $extraHeaders = [])
{
    $ch = curl_init($url);
    $headers = array_merge(['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'], $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
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

// 解析 福彩官网 cwl.gov.cn 返回（官方源，仅福彩）
// 典型结构：{"state":0,"message":"success","result":{"result":[{ "code":"2026097","openCode":"05,16,24,26,29,30+02","openTime":"2026-08-23 21:15:00", ... }]}}
function parseCwl($json, $type)
{
    if (!isset($json['state']) || (string)$json['state'] !== '0') {
        // 部分返回可能无 state 字段，容错继续
    }
    // 兼容多层 result 嵌套或直接数组
    $inner = $json['result'] ?? $json;
    $list  = $inner['result'] ?? $inner;
    if (!is_array($list)) return false;
    if (isset($list['code']) || isset($list['expect'])) $list = [$list]; // 单条
    if (empty($list)) return false;
    $rows = [];
    foreach ($list as $item) {
        $issue = $item['code'] ?? $item['expect'] ?? null;
        // 优先用 openCode（"红球,...,红球+蓝球"），其次 red/blue 字段
        $red  = [];
        $blue = [];
        if (isset($item['openCode']) && $item['openCode'] !== '') {
            $parts = explode('+', str_replace('|', '+', $item['openCode']));
            $red   = explode(',', $parts[0]);
            $blue  = isset($parts[1]) ? explode(',', $parts[1]) : [];
        } elseif (isset($item['red']) && isset($item['blue'])) {
            $red   = is_array($item['red'])   ? $item['red']   : explode(',', $item['red']);
            $blue  = is_array($item['blue'])  ? $item['blue']  : explode(',', $item['blue']);
        }
        $red   = array_map('intval', array_filter($red));
        $blue  = array_map('intval', array_filter($blue));
        if (!$issue || empty($red)) continue;
        $rows[] = [
            'issue'     => $issue,
            'red'       => implode(',', $red),
            'blue'      => implode(',', $blue),
            'open_time' => $item['openTime'] ?? $item['open_time'] ?? $item['date'] ?? null,
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

// 抓取单个彩种（按优先级遍历 API 列表，任一成功即可；缺该彩种 URL 的源自动跳过）
function fetchLottery($type)
{
    global $CONFIG;
    foreach ($CONFIG['apis'] as $api) {
        $url = $api[$type] ?? null;
        if (empty($url)) {
            echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 不含该彩种，跳过\n";
            continue;
        }
        $headers = $api['headers'] ?? [];
        $body = httpGet($url, 10, $headers);
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
