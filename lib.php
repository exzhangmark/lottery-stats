<?php
/**
 * 公共抓取 / 解析库（不依赖 Workerman / vendor）。
 * 同时被以下脚本复用：
 *   - worker/lottery_worker.php  常驻抓取进程（Workerman 定时器）
 *   - worker/backfill.php        历史开奖数据回填脚本
 * 仅使用 PHP 内置 cURL + JSON，无需 composer install 即可运行。
 */

// ---------- 配置 ----------
// 数据源按数组顺序作为优先级：任一成功即采用，全部失败才 1 小时后重试。
// 双色球(ssq)主源：福彩官网 cwl.gov.cn（仅福彩，不含体彩）
// 大乐透(dlt)主源：体彩官网 lottery.gov.cn（数据接口 webapi.sporttery.cn，gameNo=85）—— 官方源，优先级最高
// 备用：huiniao、caipiaodate（两者均覆盖双色球与大乐透，负责兜底）
//
// URL 中的 %d 为页码占位符；paginate=false 的源不分页（仅取最新一页）。
$CONFIG = [
    'apis' => [
        'official_cwl' => [
            'name'     => 'cwl.gov.cn (福彩官方)',
            'ssq'      => 'https://www.cwl.gov.cn/cwl_admin/front/cwlkj/search/kjxx/findDrawNotice?name=ssq&currentPage=%d&pageSize=20',
            'dlt'      => null,   // 福彩官网不含体彩大乐透，自动跳过，回退备用源
            'parse'    => 'parseCwl',
            'headers'  => ['Referer: http://www.cwl.gov.cn/'],
            'paginate' => true,
        ],
        'official_tiyu' => [
            'name'     => 'lottery.gov.cn (体彩官方)',
            'ssq'      => null,   // 体彩官网不含福利彩票双色球，自动跳过
            'dlt'      => 'https://webapi.sporttery.cn/gateway/lottery/getHistoryPageListV1.qry?gameNo=85&provinceId=0&pageSize=30&isVerify=1&pageNo=%d&termLimits=0',
            'parse'    => 'parseTiyu',
            'headers'  => ['Referer: https://www.lottery.gov.cn/kj/kjlb.html?dlt'],
            'paginate' => true,   // %d 映射到 pageNo，配合 backfill 翻页回填历史
        ],
        'backup1' => [
            'name'     => 'huiniao',
            'ssq'      => 'http://api.huiniao.top/interface/home/lotteryHistory?type=ssq&page=%d&limit=20',
            'dlt'      => 'http://api.huiniao.top/interface/home/lotteryHistory?type=dlt&page=%d&limit=20',
            'parse'    => 'parseHuiniao',
            'paginate' => true,
        ],
        'backup2' => [
            'name'     => 'caipiaodate',
            'ssq'      => 'https://www.caipiaodate.com/foregroundPCController/void.do?code=ssq&rows=20&format=json',
            'dlt'      => 'https://www.caipiaodate.com/foregroundPCController/void.do?code=dlt&rows=20&format=json',
            'parse'    => 'parseCaipiaodate',
            'paginate' => false,  // 不确定分页参数，仅作为单页兜底
        ],
    ],
    'retry_seconds' => 3600,   // 读取失败 1 小时后重试
    'loop_seconds'  => 600,    // 正常轮询间隔 10 分钟
    'page_size'     => 20,     // 每页抓取条数
];

// ---------- 抓取 ----------
function httpGet($url, $timeout = 10, $extraHeaders = [])
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

// 解析 体彩官网 lottery.gov.cn 返回（数据接口 webapi.sporttery.cn）
// 典型结构：{"errorCode":"0","success":true,"value":{"list":[{"lotteryDrawNum":"26096","lotteryDrawResult":"08 09 10 11 25 04 12","lotteryDrawTime":"2026-08-24",...}],"pages":98,"total":2914}}
// lotteryDrawResult 为空格分隔：前 5 个为前区(红)，后 2 个为后区(蓝)
function parseTiyu($json, $type)
{
    if (($json['errorCode'] ?? null) !== '0' && ($json['success'] ?? false) !== true) return false;
    $value = $json['value'] ?? null;
    if (!is_array($value)) return false;
    $list = $value['list'] ?? [];
    if (empty($list)) return false;
    $rows = [];
    foreach ($list as $item) {
        $issue  = $item['lotteryDrawNum'] ?? null;
        $result = $item['lotteryDrawResult'] ?? '';
        if (!$issue || !$result) continue;
        $nums = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($result))), 'strlen'));
        if (count($nums) < 7) continue;          // 大乐透需 5 前区 + 2 后区
        $red   = array_map('intval', array_slice($nums, 0, 5));
        $blue  = array_map('intval', array_slice($nums, 5, 2));
        $rows[] = [
            'issue'     => $issue,
            'red'       => implode(',', $red),
            'blue'      => implode(',', $blue),
            'open_time' => $item['lotteryDrawTime'] ?? null,
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

// 抓取单个彩种的「某一页」（page 从 1 开始），成功返回 rows 数组，失败/空返回 false
function fetchPageRows($type, $api, $page, $pageSize = 20)
{
    if (empty($api[$type])) return false;
    $tpl = $api[$type];
    $url = !empty($api['paginate']) ? sprintf($tpl, $page) : $tpl;
    $body = httpGet($url, 10, $api['headers'] ?? []);
    if ($body === false) return false;
    $json = decodeJson($body);
    if ($json === false) return false;
    $rows = call_user_func($api['parse'], $json, $type);
    if (empty($rows)) return false;
    return $rows;
}

// 抓取最新一期（page=1），按优先级遍历，任一成功即返回 true
function fetchLatest($type)
{
    global $CONFIG;
    foreach ($CONFIG['apis'] as $api) {
        if (empty($api[$type])) {
            echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 不含该彩种，跳过\n";
            continue;
        }
        $rows = fetchPageRows($type, $api, 1, $CONFIG['page_size']);
        if ($rows === false) {
            echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 第 1 页读取失败\n";
            continue;
        }
        $saved = saveRows($type, $rows);
        echo date('Y-m-d H:i:s') . " [{$type}] {$api['name']} 成功，抓取 " . count($rows) . " 期，新增 {$saved} 期\n";
        return true;
    }
    return false;
}
