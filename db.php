<?php
/**
 * 公共数据层（不依赖 Workerman / vendor）。
 * 同时被 api/index.php（HTTP 接口）与 worker/lottery_worker.php（抓取进程）复用。
 * 仅使用 PHP 内置 PDO + SQLite 扩展，无需 composer install 即可运行接口。
 */

// ---------- 数据库 ----------
function db()
{
    static $pdo;
    if ($pdo) return $pdo;
    $dbFile = __DIR__ . '/data/lottery.sqlite';
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
    // 推送订阅表：访客在网站填入自己的息知 key + 偏好的选号方案 + 时间范围，开奖/开奖日提醒时一并推送
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        xizhi_key TEXT NOT NULL,
        scheme TEXT NOT NULL DEFAULT 'cold',
        draw_push INTEGER NOT NULL DEFAULT 1,
        stat_range TEXT NOT NULL DEFAULT 'year',
        ip TEXT,
        status INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        UNIQUE(xizhi_key)
    )");
    // 兼容旧库：已存在 subscribers 表但缺少 draw_push / range 列时补上（列已存在则忽略报错）
    try {
        $pdo->exec("ALTER TABLE subscribers ADD COLUMN draw_push INTEGER NOT NULL DEFAULT 1");
    } catch (\Throwable $e) { /* 列已存在，忽略 */ }
    try {
        // 兼容更早的保留字 bug：若旧库曾用 range 作列名（SQLite 保留字，会报 SQL 语法错误），重命名为 stat_range
        $pdo->exec("ALTER TABLE subscribers RENAME COLUMN range TO stat_range");
    } catch (\Throwable $e) { /* 旧列不存在或已改名，忽略 */ }
    try {
        $pdo->exec("ALTER TABLE subscribers ADD COLUMN stat_range TEXT NOT NULL DEFAULT 'year'");
    } catch (\Throwable $e) { /* 列已存在，忽略 */ }
    // 通用键值表：用于记录「提醒推送防重复」的日期标记等
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (
        k TEXT PRIMARY KEY,
        v TEXT
    )");
    // 订阅接口频限表（每 IP 每小时最多 N 次）
    $pdo->exec("CREATE TABLE IF NOT EXISTS sub_rate (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        ts INTEGER NOT NULL
    )");
    return $pdo;
}

// ---------- 意见防攻击：速率限制（基于 IP + 时间窗）----------
// 使用反馈表同库的时间戳表，限制每 IP 每 60 秒最多 3 条。
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
