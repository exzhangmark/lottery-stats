<?php
/**
 * 彩票开奖数据抓取 Worker（Workerman 定时器，常驻进程）
 * 双色球（ssq）与大乐透（dlt）按优先级从多个免费 API 读取，失败则 1 小时后重试。
 * 依赖：workerman/workerman  (composer require workerman/workerman)
 *
 * 抓取 / 解析逻辑见 lib.php（与 backfill.php 共用）。
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../db.php';

use Workerman\Worker;
use Workerman\Timer;

// 主任务：抓取最新一期；任一彩种失败则 1 小时后重试
function runFetch()
{
    global $CONFIG;
    $okSsq = fetchLatest('ssq');
    $okDlt = fetchLatest('dlt');
    // 开奖日 12:00 选号建议推送（worker 常驻，命中即推；非 12 点 / 非开奖日自动跳过）
    maybePushReminder();

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
    runFetch();                                       // 启动时立即抓取一次
    Timer::add($CONFIG['loop_seconds'], 'runFetch');  // 之后每 10 分钟轮询
};

Worker::runAll();
