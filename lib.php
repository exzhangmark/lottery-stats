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
        // 抓到新增开奖数据 → 通过息知推送最新一期给 owner + 所有订阅者。rows[0] 为该页最新一期。
        if ($saved > 0 && !empty($rows[0])) {
            pushDrawResult($type, $rows[0]);
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
    $file = __DIR__ . '/notify_config.php';
    if (file_exists($file)) {
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

// 统计某彩种全量数据：出现次数 + 遗漏期数（最新一期遗漏=0，从未开出=总期数）
function computeStats($type)
{
    $redMax  = $type === 'ssq' ? 33 : 35;
    $blueMax = $type === 'ssq' ? 16 : 12;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT red, blue, issue, open_time FROM results WHERE type=? ORDER BY issue DESC");
    $stmt->execute([$type]);
    $redCount  = array_fill(1, $redMax, 0);
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
function generateNumbers($type, $scheme)
{
    $rule = $type === 'ssq'
        ? ['redN' => 6, 'blueN' => 1, 'redMax' => 33, 'blueMax' => 16]
        : ['redN' => 5, 'blueN' => 2, 'redMax' => 35, 'blueMax' => 12];
    $stats = computeStats($type);
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
function allRecipients()
{
    $cfg = notifyConfig();
    $list = [];
    if (!empty($cfg['owner_key'])) {
        $list[] = ['key' => $cfg['owner_key'], 'scheme' => $cfg['default_scheme'] ?? 'cold', 'who' => 'owner'];
    }
    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT xizhi_key, scheme FROM subscribers WHERE status=1");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($r['xizhi_key'])) {
                $list[] = ['key' => $r['xizhi_key'], 'scheme' => $r['scheme'] ?? 'cold', 'who' => 'sub'];
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
    foreach (allRecipients() as $rc) {
        $res = xizhiSend($rc['key'], $title, $content);
        echo date('Y-m-d H:i:s') . " [notify] 开奖推送 {$type} 第{$row['issue']}期 → "
            . ($res['ok'] ? 'OK' : 'FAIL ' . $res['msg']) . "\n";
    }
}

// 开奖日 12:00 提醒：按星期判断当日开奖彩种，按各收件人偏好方案生成号码并推送（当日仅一次）
function maybePushReminder()
{
    $cfg = notifyConfig();
    if (empty($cfg['enabled'])) return;
    if ((int)date('G') !== 12) return;   // 仅开奖日 12:00 触发（worker 每 10 分钟轮询，命中即推，meta 表防当日重复）
    $w = (int)date('w');   // 0=周日 .. 6=周六
    // 双色球：周一(1)周三(3)周日(0)；大乐透：周二(2)周四(4)周六(6)；周五(5)无开奖
    $drawMap = ['ssq' => [0, 1, 3], 'dlt' => [2, 4, 6]];
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
        $name = $type === 'ssq' ? '福利双色球' : '体彩大乐透';
        $cnt = 0;
        foreach (allRecipients() as $rc) {
            $scheme = $rc['scheme'] ?? 'cold';
            $gen = generateNumbers($type, $scheme);
            $schemeName = SCHEME_NAMES[$scheme] ?? $scheme;
            $title = "{$name} 今日开奖 · {$schemeName} 选号建议";
            $content = "方案：{$schemeName}\n红球：" . implode(' ', $gen['red'])
                . "\n蓝球：" . implode(' ', $gen['blue'])
                . ($gen['note'] ? "\n注：{$gen['note']}" : '')
                . "\n\n开奖日 " . $date . " 12:00 推送，仅供参考";
            $res = xizhiSend($rc['key'], $title, $content);
            echo date('Y-m-d H:i:s') . " [reminder] {$type}/{$scheme} → "
                . ($res['ok'] ? 'OK' : 'FAIL ' . $res['msg']) . "\n";
            $cnt++;
        }
        $pdo->prepare("INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
            ->execute([$k, $date]);
        echo date('Y-m-d H:i:s') . " [reminder] {$type} 共推送 {$cnt} 人\n";
    }
}
