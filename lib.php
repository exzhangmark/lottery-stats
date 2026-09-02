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
// 主源：福彩官网 cwl.gov.cn（仅覆盖福利彩票，如双色球；不含体彩大乐透）
// 备用：huiniao、caipiaodate（两者均覆盖双色球与大乐透，负责兜底）
//
// URL 中的 %d 为页码占位符；paginate=false 的源不分页（仅取最新一页）。
$CONFIG = [
    'apis' => [
        'official' => [
            'name'     => 'cwl.gov.cn',
            'ssq'      => 'https://www.cwl.gov.cn/cwl_admin/front/cwlkj/search/kjxx/findDrawNotice?name=ssq&currentPage=%d&pageSize=20',
            'dlt'      => null,   // 福彩官网不含体彩大乐透，自动跳过，回退备用源
            'parse'    => 'parseCwl',
            'headers'  => ['Referer: http://www.cwl.gov.cn/'],
            'paginate' => true,
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
        // 开奖结果推送：以「是否已推送」标记为准，而非仅「是否新增」。
        // 旧逻辑只在 $saved>0（新增期）时推，导致：若某期先被抓进库（网站访问触发 action=fetch /
        // worker 早先抓过），$saved 后续变 0，推送永久不触发 →「入库了却没推」。
        // 改为按 results.result_notified 标记逐期推送：只要还没推过就一定会推（自愈），且每期仅推一次。
        $pdo = db();
        $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $nPush = 0;
        foreach ($rows as $r) {
            if (empty($r['issue'])) continue;
            if (!empty($r['open_time']) && $r['open_time'] < $cutoff) continue; // 仅推近 7 天，避免回溯远古历史刷屏
            $chk = $pdo->prepare("SELECT result_notified FROM results WHERE type=? AND issue=?");
            $chk->execute([$type, $r['issue']]);
            if ((int)$chk->fetchColumn() === 1) continue;
            pushDrawResult($type, $r);
            $pdo->prepare("UPDATE results SET result_notified=1 WHERE type=? AND issue=?")->execute([$type, $r['issue']]);
            if (++$nPush >= 10) break; // 单次最多补推 10 期，防刷屏
        }
        if ($nPush > 0) echo date('Y-m-d H:i:s') . " [{$type}] 已推送开奖结果 {$nPush} 期\n";
        // 每期开奖后，核对针对该期的选号存档并更新中奖结果
        foreach ($rows as $r) {
            if (!empty($r['issue'])) {
                $u = checkWinsForIssue($type, $r['issue'], $r['red'], $r['blue']);
                if ($u > 0) echo date('Y-m-d H:i:s') . " [{$type}] 第 {$r['issue']} 期核对选号存档 {$u} 条\n";
            }
        }
        return true;
    }
    return false;
}

// ---------- 推送（息知 / 个人微信） ----------
// 配置见 notify_config.php（由 notify_config.example.php 复制而来，含私密 owner_key，已 gitignore）
// 息知接口：GET https://xizhi.qqoq.net/{key}.send?title=标题&content=内容
function notifyConfig()
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    // 兼容两种部署布局：
    //   1) notify_config.php 与 lib.php 同级（推荐，webroot 内）
    //   2) notify_config.php 在 lib.php 的上级目录（本地开发常见）
    $candidates = [
        __DIR__ . '/notify_config.php',
        dirname(__DIR__) . '/notify_config.php',
    ];
    $file = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) { $file = $c; break; }
    }
    if ($file) {
        $cfg = require $file;
    } else {
        $cfg = ['enabled' => false, 'owner_key' => '', 'default_scheme' => 'cold'];
    }
    return $cfg;
}

// 各方案中文名（与前端 public/index.html 的 SCHEMES 保持一致）
const SCHEME_NAMES = [
    'cold'    => '冷门预选',
    'hot'     => '热门预选',
    'mixed'   => '冷热结合',
    'omit'    => '遗漏值优先',
    'balance' => '随机均衡',
    'avg'     => '均值回归',
    'repeat'  => '历史复刻',
    'lucky'   => '吉利玄学',
    'flat'    => '躺平机选',
];

// 时间范围中文名（与前端 RANGE_LABELS 保持一致）
const RANGE_LABELS = [
    'earliest' => '最早',
    'month'    => '本月',
    'year'     => '本年',
    'lastyear' => '去年',
    '3y'       => '三年内',
    '5y'       => '五年内',
    '10y'      => '十年内',
    'all'      => '全部',
];

// ---------- 选号算法（与前端 public/index.html 的 SCHEMES 逻辑一一对应） ----------
function php_countEntries($counts)
{
    $e = [];
    foreach ($counts as $k => $v) $e[] = [(int)$k, (int)$v];
    return $e;
}

// 冷门预选 / 热门预选：cold=true 取次数最少，否则取最多；次数并列随机打散；输出升序
function pickNumbers($entries, $n, $cold)
{
    $arr = array_map(function ($e) {
        return ['num' => $e[0], 'c' => $e[1], 'r' => mt_rand() / getrandmax()];
    }, $entries);
    usort($arr, function ($a, $b) use ($cold) {
        $cmp = $cold ? ($a['c'] <=> $b['c']) : ($b['c'] <=> $a['c']);
        return $cmp !== 0 ? $cmp : ($a['r'] <=> $b['r']);
    });
    $sel = array_slice($arr, 0, $n);
    usort($sel, function ($a, $b) { return $a['num'] <=> $b['num']; });
    return array_column($sel, 'num');
}

// 冷热结合：一半取最热、一半取最冷；奇数个时多出的 1 个随机分给某一侧；去重 + 不足补齐
function pickMixed($entries, $n)
{
    $half = intdiv($n, 2);
    $hotTake = $half; $coldTake = $half;
    if ($n % 2 === 1) { if (mt_rand(0, 1) === 0) $hotTake++; else $coldTake++; }
    $sorted = array_map(function ($e) {
        return ['num' => $e[0], 'c' => $e[1], 'r' => mt_rand() / getrandmax()];
    }, $entries);
    usort($sorted, function ($a, $b) {
        $cmp = $b['c'] <=> $a['c']; return $cmp !== 0 ? $cmp : ($a['r'] <=> $b['r']);
    });
    $picks = array_slice($sorted, 0, $hotTake);
    if ($coldTake > 0) $picks = array_merge($picks, array_slice($sorted, -$coldTake));
    $seen = []; $uniq = [];
    foreach ($picks as $p) { if (!in_array($p['num'], $seen, true)) { $seen[] = $p['num']; $uniq[] = $p; } }
    foreach ($sorted as $p) { if (count($uniq) >= $n) break; if (!in_array($p['num'], $seen, true)) { $seen[] = $p['num']; $uniq[] = $p; } }
    usort($uniq, function ($a, $b) { return $a['num'] <=> $b['num']; });
    return array_column(array_slice($uniq, 0, $n), 'num');
}

// 遗漏值优先：取遗漏期数最长（最久未开出）的号码；同遗漏随机打散
function pickOmit($omitEntries, $n)
{
    $arr = array_map(function ($e) {
        return ['num' => $e[0], 'c' => (int)($e[1] ?: 0), 'r' => mt_rand() / getrandmax()];
    }, $omitEntries);
    usort($arr, function ($a, $b) {
        $cmp = $b['c'] <=> $a['c']; return $cmp !== 0 ? $cmp : ($a['r'] <=> $b['r']);
    });
    $sel = array_slice($arr, 0, $n);
    usort($sel, function ($a, $b) { return $a['num'] <=> $b['num']; });
    return array_column($sel, 'num');
}

// 随机均衡：1~maxNum 均分 n 段，每段随机取 1 个
function pickBalanced($maxNum, $n)
{
    $picks = []; $segSize = (int)ceil($maxNum / $n);
    for ($s = 0; $s < $n; $s++) {
        $lo = $s * $segSize + 1;
        $hi = min($maxNum, ($s + 1) * $segSize);
        if ($lo > $maxNum) break;
        $picks[] = mt_rand($lo, $hi);
    }
    sort($picks);
    return $picks;
}

// 均值回归：取出现次数与全体平均值之差绝对值最小的号码
function pickAverage($entries, $n)
{
    $counts = array_column($entries, 1);
    $avg = $counts ? array_sum($counts) / count($counts) : 0;
    $arr = array_map(function ($e) use ($avg) {
        return ['num' => $e[0], 'c' => $e[1], 'd' => abs($e[1] - $avg), 'r' => mt_rand() / getrandmax()];
    }, $entries);
    usort($arr, function ($a, $b) {
        $cmp = $a['d'] <=> $b['d']; return $cmp !== 0 ? $cmp : ($a['r'] <=> $b['r']);
    });
    $sel = array_slice($arr, 0, $n);
    usort($sel, function ($a, $b) { return $a['num'] <=> $b['num']; });
    return array_column($sel, 'num');
}

// 吉利玄学：T1 号码含 6/8；T2 数位和=6/8；T3 其它；同层随机
function luckyTier($num)
{
    $s = (string)$num;
    if (strpos($s, '6') !== false || strpos($s, '8') !== false) return 0;
    $sum = array_sum(str_split($s));
    if ($sum === 6 || $sum === 8) return 1;
    return 2;
}
function pickLucky($maxNum, $n)
{
    $arr = [];
    for ($num = 1; $num <= $maxNum; $num++) $arr[] = ['num' => $num, 't' => luckyTier($num), 'r' => mt_rand() / getrandmax()];
    usort($arr, function ($a, $b) {
        $cmp = $a['t'] <=> $b['t']; return $cmp !== 0 ? $cmp : ($a['r'] <=> $b['r']);
    });
    $sel = array_slice($arr, 0, $n);
    usort($sel, function ($a, $b) { return $a['num'] <=> $b['num']; });
    return array_column($sel, 'num');
}

// 躺平机选：全域均匀洗牌取前 n
function pickFlat($maxNum, $n)
{
    $pool = range(1, $maxNum);
    for ($i = count($pool) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
    }
    $sel = array_slice($pool, 0, $n);
    sort($sel);
    return $sel;
}

// 数据库内某彩种最早的开奖日期（Y-m-d H:i:s）；无数据返回 null
function earliestOpenTime($type)
{
    static $cache = [];
    if (array_key_exists($type, $cache)) return $cache[$type];
    $cache[$type] = null;
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT MIN(open_time) FROM results WHERE type=?");
        $stmt->execute([$type]);
        $v = $stmt->fetchColumn();
        if ($v) $cache[$type] = $v;
    } catch (Exception $e) {
        // 表尚未就绪时返回 null（= 不过滤）
    }
    return $cache[$type];
}

// 时间范围 → 起止日期窗口（用于按时间窗口统计）。返回 null 表示「全部」。
// 仅按 open_time 做字符串比较（数据格式为 Y-m-d H:i:s，字典序等价于时间序）。
// $type 仅在处理 'earliest' 时需要（查该彩种最早开奖日）。
function rangeWindow($range, $type = null)
{
    $y = (int)date('Y');
    switch ($range) {
        case 'earliest':
            $start = earliestOpenTime($type);
            return $start ? ['start' => $start, 'end' => null] : null;
        case 'month':    return ['start' => date('Y-m-01'), 'end' => null];
        case 'year':     return ['start' => $y . '-01-01',  'end' => null];
        case 'lastyear': return ['start' => ($y - 1) . '-01-01', 'end' => $y . '-01-01'];
        case '3y':       return ['start' => ($y - 3) . '-01-01', 'end' => null];
        case '5y':       return ['start' => ($y - 5) . '-01-01', 'end' => null];
        case '10y':      return ['start' => ($y - 10) . '-01-01', 'end' => null];
        default:         return null;   // all / 未知 → 不过滤
    }
}

// 统计某彩种数据：出现次数 + 遗漏期数（最新一期遗漏=0，从未开出=总期数）
// $range 支持 month/year/lastyear/3y/5y/10y/all；按 open_time 过滤统计窗口
function computeStats($type, $range = 'all')
{
    $redMax  = $type === 'ssq' ? 33 : 35;
    $blueMax = $type === 'ssq' ? 16 : 12;
    $win = rangeWindow($range, $type);
    $sql  = "SELECT red, blue, issue, open_time FROM results WHERE type=?";
    $params = [$type];
    if ($win) {
        $sql .= " AND open_time >= ?";
        $params[] = $win['start'];
        if ($win['end']) {
            $sql .= " AND open_time < ?";
            $params[] = $win['end'];
        }
    }
    $sql .= " ORDER BY issue DESC";
    $pdo = db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $redCount  = array_fill( 1, $redMax, 0);
    $blueCount = array_fill(1, $blueMax, 0);
    $redOmit   = array_fill(1, $redMax, null);
    $blueOmit  = array_fill(1, $blueMax, null);
    $rows = []; $idx = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach (explode(',', $r['red']) as $n) {
            $n = (int)$n;
            if ($n >= 1 && $n <= $redMax) { $redCount[$n]++; if ($redOmit[$n] === null) $redOmit[$n] = $idx; }
        }
        foreach (explode(',', $r['blue']) as $n) {
            $n = (int)$n;
            if ($n >= 1 && $n <= $blueMax) { $blueCount[$n]++; if ($blueOmit[$n] === null) $blueOmit[$n] = $idx; }
        }
        $rows[] = $r; $idx++;
    }
    $total = $idx;
    for ($n = 1; $n <= $redMax; $n++)  if ($redOmit[$n]  === null) $redOmit[$n]  = $total;
    for ($n = 1; $n <= $blueMax; $n++) if ($blueOmit[$n] === null) $blueOmit[$n] = $total;
    return ['red' => $redCount, 'blue' => $blueCount, 'redOmit' => $redOmit, 'blueOmit' => $blueOmit, 'rows' => $rows, 'total' => $total];
}

// 历史开奖（不含今年），供「历史复刻」方案随机抽取
function historyRows($type)
{
    $s = computeStats($type);
    $cut = date('Y') . '-01-01';
    $out = [];
    foreach ($s['rows'] as $r) {
        if (($r['open_time'] ?? '') < $cut) $out[] = $r;
    }
    return $out;
}

// 按方案生成号码：返回 ['red'=>[...], 'blue'=>[...], 'note'=>'']
// $range 支持 month/year/lastyear/3y/5y/10y/all，仅影响 cold/hot/mixed/omit/avg 的统计窗口
function generateNumbers($type, $scheme, $range = 'all')
{
    $rule = $type === 'ssq'
        ? ['redN' => 6, 'blueN' => 1, 'redMax' => 33, 'blueMax' => 16]
        : ['redN' => 5, 'blueN' => 2, 'redMax' => 35, 'blueMax' => 12];
    $stats = computeStats($type, $range);
    $redEntries  = php_countEntries($stats['red']);
    $blueEntries = php_countEntries($stats['blue']);
    $note = '';
    if ($scheme === 'balance') {
        $reds  = pickBalanced($rule['redMax'], $rule['redN']);
        $blues = pickBalanced($rule['blueMax'], $rule['blueN']);
    } elseif ($scheme === 'omit') {
        if (!empty($stats['redOmit']) && !empty($stats['blueOmit'])) {
            $reds  = pickOmit(php_countEntries($stats['redOmit']), $rule['redN']);
            $blues = pickOmit(php_countEntries($stats['blueOmit']), $rule['blueN']);
        } else {
            $reds = pickBalanced($rule['redMax'], $rule['redN']);
            $blues = pickBalanced($rule['blueMax'], $rule['blueN']);
            $note = '（遗漏值数据缺失，按随机均衡生成）';
        }
    } elseif ($scheme === 'mixed') {
        $reds  = pickMixed($redEntries, $rule['redN']);
        $blues = pickMixed($blueEntries, $rule['blueN']);
    } elseif ($scheme === 'avg') {
        $reds  = pickAverage($redEntries, $rule['redN']);
        $blues = pickAverage($blueEntries, $rule['blueN']);
    } elseif ($scheme === 'lucky') {
        $reds  = pickLucky($rule['redMax'], $rule['redN']);
        $blues = pickLucky($rule['blueMax'], $rule['blueN']);
    } elseif ($scheme === 'flat') {
        $reds  = pickFlat($rule['redMax'], $rule['redN']);
        $blues = pickFlat($rule['blueMax'], $rule['blueN']);
    } elseif ($scheme === 'repeat') {
        $hist = historyRows($type);
        if ($hist) {
            $row = $hist[mt_rand(0, count($hist) - 1)];
            $rNums = explode(',', $row['red']);
            $bNums = explode(',', $row['blue']);
            if (count($rNums) === $rule['redN'] && count($bNums) === $rule['blueN']) {
                $reds = array_map('intval', $rNums);
                $blues = array_map('intval', $bNums);
                sort($reds); sort($blues);
                $note = "（复制自历史第 {$row['issue']} 期）";
            } else { $reds = $blues = null; }
        }
        if (empty($reds)) {
            $reds  = pickFlat($rule['redMax'], $rule['redN']);
            $blues = pickFlat($rule['blueMax'], $rule['blueN']);
            $note  = '（历史数据为空，按随机生成）';
        }
    } else { // cold / hot
        $cold = $scheme === 'cold';
        $reds  = pickNumbers($redEntries, $rule['redN'], $cold);
        $blues = pickNumbers($blueEntries, $rule['blueN'], $cold);
    }
    return ['red' => $reds, 'blue' => $blues, 'note' => $note];
}

// ---------- 选号存档与中奖核对 ----------

// 中奖规则表：[红球命中数, 蓝球命中数, 奖级, 固定奖金(元)]；奖金为 0 表示浮动奖
function prizeRules($type)
{
    if ($type === 'ssq') {
        // 双色球：红球6 + 蓝球1。一/二等奖浮动；三~六等奖固定。
        return [
            [6, 1, 1, 0], [6, 0, 2, 0],
            [5, 1, 3, 3000],
            [5, 0, 4, 200], [4, 1, 4, 200],
            [4, 0, 5, 10], [3, 1, 5, 10],
            [2, 1, 6, 5], [1, 1, 6, 5], [0, 1, 6, 5],
        ];
    }
    // 大乐透：前区5 + 后区2。一/二等奖浮动；三~九等奖固定。
    return [
        [5, 2, 1, 0], [5, 1, 2, 0],
        [5, 0, 3, 10000],
        [4, 2, 4, 3000],
        [4, 1, 5, 300],
        [3, 2, 6, 200], [4, 0, 6, 200],
        [3, 1, 7, 100], [2, 2, 7, 100],
        [3, 0, 8, 15], [2, 1, 8, 15], [1, 2, 8, 15],
        [2, 0, 9, 5], [1, 1, 9, 5], [0, 2, 9, 5], [1, 0, 9, 5], [0, 1, 9, 5],
    ];
}

// 判定一组选号是否中奖，返回 ['level'=>奖级, 'amount'=>金额]
function checkPickWin($type, $redPick, $bluePick, $redResult, $blueResult)
{
    $redMatch  = count(array_intersect($redPick, $redResult));
    $blueMatch = count(array_intersect($bluePick, $blueResult));
    foreach (prizeRules($type) as $r) {
        if ($r[0] === $redMatch && $r[1] === $blueMatch) {
            return ['level' => $r[2], 'amount' => $r[3]];
        }
    }
    return ['level' => 0, 'amount' => 0];
}

// 某期开奖后，核对所有针对该期的选号存档并更新中奖结果
function checkWinsForIssue($type, $issue, $redResult, $blueResult)
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, red, blue FROM picks WHERE type=? AND issue=?");
    $stmt->execute([$type, $issue]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $redResult  = array_map('intval', explode(',', $redResult));
    $blueResult = array_map('intval', explode(',', $blueResult));
    $updated = 0;
    foreach ($rows as $row) {
        $redPick  = array_map('intval', explode(',', $row['red']));
        $bluePick = array_map('intval', explode(',', $row['blue']));
        $res = checkPickWin($type, $redPick, $bluePick, $redResult, $blueResult);
        $up = $pdo->prepare("UPDATE picks SET prize_level=?, prize_amount=?, status='checked', checked_at=datetime('now','localtime') WHERE id=?");
        $up->execute([$res['level'], $res['amount'], $row['id']]);
        $updated += $up->rowCount();
    }
    return $updated;
}

// 补核对所有「尚未核对」且对应期号已开奖的选号存档（用于回填/修复后手动执行）
function checkAllPendingWins()
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT p.type, p.issue, p.red, p.blue, r.red AS rred, r.blue AS rblue
         FROM picks p JOIN results r ON r.type=p.type AND r.issue=p.issue
         WHERE p.status='pending'"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $row) {
        $res = checkPickWin(
            $row['type'],
            array_map('intval', explode(',', $row['red'])),
            array_map('intval', explode(',', $row['blue'])),
            array_map('intval', explode(',', $row['rred'])),
            array_map('intval', explode(',', $row['rblue']))
        );
        $up = $pdo->prepare("UPDATE picks SET prize_level=?, prize_amount=?, status='checked', checked_at=datetime('now','localtime') WHERE type=? AND issue=? AND red=? AND blue=?");
        $up->execute([$res['level'], $res['amount'], $row['type'], $row['issue'], $row['red'], $row['blue']]);
        $n += $up->rowCount();
    }
    return $n;
}

// 各彩种开奖星期：双色球 周日/二/四（date('w'): 0=周日），大乐透 周一/三/六
function drawWeekdays($type)
{
    return $type === 'ssq' ? [0, 2, 4] : [1, 3, 6];
}

// 返回 $afterDate 之后第一个开奖日（含跨年）
function nextDrawDate($type, $afterDate)
{
    $wd = drawWeekdays($type);
    $d = new DateTime($afterDate);
    for ($i = 0; $i < 14; $i++) {
        $d->modify('+1 day');
        if (in_array((int)$d->format('w'), $wd, true)) return $d->format('Y-m-d');
    }
    return null;
}

// 由开奖日推算该年内的期序（issue = 该年自 1/1 起的第几个开奖日，首期为 001）
function issueForDate($type, $dateStr)
{
    $wd = drawWeekdays($type);
    $dt = new DateTime($dateStr);
    $year = (int)$dt->format('Y');
    $start = new DateTime("$year-01-01");
    $cnt = 0;
    while ($start <= $dt) {
        if (in_array((int)$start->format('w'), $wd, true)) $cnt++;
        $start->modify('+1 day');
    }
    return $year . str_pad($cnt, 3, '0', STR_PAD_LEFT);
}

// 期号 +1（保留年份前缀）：'2026097' -> '2026098'，'26096' -> '26097'
// 跨年（期号超过单年自然上限 ~160）时年份进一、期号归 001
function incIssue($issue)
{
    if (!preg_match('/^(.*?)(\d{3})$/', $issue, $m)) return $issue;
    $prefix = $m[1];
    $num = (int)$m[2] + 1;
    if ($num > 160) {
        $prefix = (string)((int)$prefix + 1);
        $num = 1;
    }
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// 推算下一期期号：直接基于 results 中最新一期【官方真实期号】 +1（与官方完全对齐）
// 规则：候选期 = 最近期 +1；若该候选期已存在于 results（说明已开奖），则再 +1 才是真正待开奖的下一期。
// 这样在「开奖日 20 点后 ~ 开奖前」窗口（数据库尚未抓到当天期，最近期仍是上一期）生成的选号，
// 目标期号 = 当天期（仅 +1），而不会因数据库未更新而误跳到下一期。
// 仅在没有任何开奖数据时回退到「按日期推算」做兜底。
function getNextIssue($type)
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT issue FROM results WHERE type=? ORDER BY issue DESC LIMIT 1");
    $stmt->execute([$type]);
    $last = $stmt->fetchColumn();
    if (!$last) {
        $next = nextDrawDate($type, date('Y-m-d'));
        if (!$next) return null;
        return issueForDate($type, $next);
    }
    $candidate = incIssue($last);
    // 候选期若已开奖（已存在于 results），则真正待开奖的下一期 = candidate +1
    $chk = $pdo->prepare("SELECT 1 FROM results WHERE type=? AND issue=?");
    $chk->execute([$type, $candidate]);
    if ($chk->fetchColumn()) {
        $candidate = incIssue($candidate);
    }
    return $candidate;
}

// 保存一次选号存档（自动推算目标期号）。返回 ['ok','issue','id','msg']
function savePick($type, $redArr, $blueArr, $scheme)
{
    $redMax  = $type === 'ssq' ? 33 : 35;
    $blueMax = $type === 'ssq' ? 16 : 12;
    $redN    = $type === 'ssq' ? 6  : 5;
    $blueN   = $type === 'ssq' ? 1  : 2;
    $redArr  = array_values(array_filter(array_map('intval', $redArr),  fn($n) => $n >= 1 && $n <= $redMax));
    $blueArr = array_values(array_filter(array_map('intval', $blueArr), fn($n) => $n >= 1 && $n <= $blueMax));
    if (count($redArr) !== $redN || count($blueArr) !== $blueN) {
        return ['ok' => false, 'msg' => '号码数量不正确'];
    }
    if (count(array_unique($redArr)) !== $redN) {
        return ['ok' => false, 'msg' => '红球/前区存在重复号码'];
    }
    sort($redArr); sort($blueArr);
    $red  = implode(',', $redArr);
    $blue = implode(',', $blueArr);
    $issue = getNextIssue($type);
    if (!$issue) {
        // 兜底：用今天推算的下一开奖日，保证选号仍能存档（而不因期号缺失丢数据）
        try {
            $next = nextDrawDate($type, date('Y-m-d'));
            if ($next) $issue = issueForDate($type, $next);
        } catch (\Throwable $e) { /* 忽略，走下面的失败返回 */ }
    }
    if (!$issue) return ['ok' => false, 'msg' => '无法确定目标期号（缺少历史开奖数据）'];
    $pdo = db();
    // 幂等：同 (type,issue,red,blue,scheme) 不重复插入
    $chk = $pdo->prepare("SELECT id FROM picks WHERE type=? AND issue=? AND red=? AND blue=? AND scheme=?");
    $chk->execute([$type, $issue, $red, $blue, $scheme]);
    if ($chk->fetchColumn()) {
        return ['ok' => true, 'id' => 0, 'issue' => $issue, 'msg' => '该方案该期号码已存在，未重复保存'];
    }
    $stmt = $pdo->prepare("INSERT INTO picks (type, issue, red, blue, scheme, created_at) VALUES (?,?,?,?,?,datetime('now','localtime'))");
    $stmt->execute([$type, $issue, $red, $blue, $scheme]);
    return ['ok' => true, 'id' => $pdo->lastInsertId(), 'issue' => $issue, 'msg' => '已保存'];
}

// ---------- 息知发送（GET） ----------
function xizhiSend($key, $title, $content)
{
    if (!$key) return ['ok' => false, 'msg' => 'empty key'];
    $url = 'https://xizhi.qqoq.net/' . $key . '.send?title=' . urlencode($title) . '&content=' . urlencode($content);
    $body = httpGet($url, 10);
    if ($body === false) return ['ok' => false, 'msg' => 'http fail'];
    $j = json_decode($body, true);
    $ok = isset($j['code']) && (int)$j['code'] === 200;
    return ['ok' => $ok, 'msg' => $ok ? 'OK' : ('FAIL ' . substr($body, 0, 120))];
}

// 所有收件人：站点 owner（配置中的 owner_key）+ 已订阅访客（subscribers 表）
// $forDraw=true 时仅返回「开启开奖结果推送」的订阅者（owner 始终包含）
function allRecipients($forDraw = false)
{
    $cfg = notifyConfig();
    $list = [];
    if (!empty($cfg['owner_key'])) {
        $list[] = ['key' => $cfg['owner_key'], 'scheme' => $cfg['default_scheme'] ?? 'cold', 'who' => 'owner'];
    }
    try {
        $pdo = db();
        $sql = "SELECT xizhi_key, scheme, COALESCE(draw_push,1) AS draw_push, COALESCE(stat_range,'earliest') AS stat_range FROM subscribers WHERE status=1";
        if ($forDraw) $sql .= " AND draw_push=1";
        $stmt = $pdo->query($sql);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($r['xizhi_key'])) {
                $list[] = ['key' => $r['xizhi_key'], 'scheme' => $r['scheme'] ?? 'cold', 'stat_range' => $r['stat_range'] ?? 'earliest', 'who' => 'sub'];
            }
        }
    } catch (Exception $e) { /* 表尚未创建时忽略 */ }
    return $list;
}

// 推送最新一期开奖结果给所有人（worker 抓到新增开奖时调用）
function pushDrawResult($type, $row)
{
    $cfg = notifyConfig();
    if (empty($cfg['enabled'])) return;
    $name    = $type === 'ssq' ? '福利双色球' : '体彩大乐透';
    $title   = "{$name} 第 {$row['issue']} 期开奖结果";
    $content = "红球：" . str_replace(',', ' ', $row['red'])
        . "\n蓝球：" . str_replace(',', ' ', $row['blue'])
        . "\n开奖时间：" . ($row['open_time'] ?? '');
    foreach (allRecipients(true) as $rc) {
        $res = xizhiSend($rc['key'], $title, $content);
        echo date('Y-m-d H:i:s') . " [notify] 开奖推送 {$type} 第{$row['issue']}期 → "
            . ($res['ok'] ? 'OK' : 'FAIL ' . $res['msg']) . "\n";
    }
}

// 开奖日 7:00 提醒：按星期判断当日开奖彩种，按各收件人偏好方案生成号码并推送（当日仅一次）
function maybePushReminder()
{
    $cfg = notifyConfig();
    if (empty($cfg['enabled'])) return;
    if ((int)date('G') !== 7) return;   // 仅开奖日 7:00 触发（worker 每 10 分钟轮询，命中即推，meta 表防当日重复）
    $w = (int)date('w');   // 0=周日 .. 6=周六
    // 双色球：周二(2)周四(4)周日(0，即用户说的“7”)；大乐透：周一(1)周三(3)周六(6)
    $drawMap = ['ssq' => [2, 4, 0], 'dlt' => [1, 3, 6]];
    $today = [];
    foreach ($drawMap as $type => $days) if (in_array($w, $days, true)) $today[] = $type;
    if (empty($today)) return;
    $pdo = db();
    $date = date('Y-m-d');
    foreach ($today as $type) {
        $k = 'last_reminder_' . $type;
        $stmt = $pdo->prepare("SELECT v FROM meta WHERE k=?");
        $stmt->execute([$k]);
        $done = $stmt->fetchColumn();
        if ($done === $date) continue;   // 当天已推，跳过
        $name = $type === 'ssq' ? '福利彩票双色球' : '体彩彩票大乐透';
        $hongqiu = $type === 'ssq' ? '红球' : '前区';
        $lanqiu = $type === 'ssq' ? '蓝球' : '后区';
        $cnt = 0;
        foreach (allRecipients() as $rc) {
            $scheme = $rc['scheme'] ?? 'cold';
            // 优先用订阅者自己选的时间范围，其次站点默认范围
            $range = $rc['stat_range'] ?? ($cfg['default_range'] ?? 'earliest');
            $gen = generateNumbers($type, $scheme, $range);
            // 将今日推送的选号同步入库（开奖后按 issue 核对中奖）
            try {
                $save = savePick($type, $gen['red'], $gen['blue'], $scheme);
            } catch (\Throwable $e) {
                $save = ['ok' => false, 'msg' => '异常:' . $e->getMessage()];
            }
            $saveMsg = $save['ok'] ? ("已入库 第{$save['issue']}期") : ("入库失败：" . $save['msg']);
            $schemeName = SCHEME_NAMES[$scheme] ?? $scheme;
            $rangeName  = RANGE_LABELS[$range]  ?? $range;
            $title = "{$name} 今日开奖 · {$schemeName} 选号建议";
            $content = "方案：{$schemeName}（统计范围：{$rangeName}）\n\n{$hongqiu}：" . implode(' ', $gen['red'])
                . "\n{$lanqiu}：" . implode(' ', $gen['blue'])
                . ($gen['note'] ? "\n注：{$gen['note']}" : '')
                . "\n\n开奖日 " . $date . " 7:00 推送，仅供参考";
            $res = xizhiSend($rc['key'], $title, $content);
            echo date('Y-m-d H:i:s') . " [reminder] {$type}/{$scheme}/{$range} → "
                . ($res['ok'] ? 'OK' : 'FAIL ' . $res['msg']) . " | {$saveMsg}\n";
            $cnt++;
        }
        $pdo->prepare("INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
            ->execute([$k, $date]);
        echo date('Y-m-d H:i:s') . " [reminder] {$type} 共推送 {$cnt} 人\n";
    }
}
