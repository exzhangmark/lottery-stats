<?php
/**
 * 抓取最新开奖 + 推送开奖结果（crontab 兜底脚本）
 *
 * 为什么需要它：
 *   开奖结果推送原本只挂在常驻进程 lottery_worker.php 的 fetchLatest() 里。
 *   一旦 worker 没在运行（服务器重启、进程退出、未配置自启），开奖抓取与开奖推送会全部停止，
 *   但「开奖日 7:00 选号推送」由 crontab 的 push_reminder.php 独立触发，不受影响；
 *   于是就出现「选号正常推送、开奖结果却不推送」的现象。
 *   本脚本作为与 worker 解耦的兜底，由 crontab 定时调用，保证开奖结果推送不依赖常驻进程。
 *
 * 用法（crontab，每 10 分钟运行一次）：
 *   0,10,20,30,40,50 * * * * /usr/bin/php /www/wwwroot/lottery/worker/fetch_and_notify.php >> /www/wwwroot/lottery/data/fetch.log 2>&1
 *
 * 注意：本脚本不依赖 vendor/Workerman，纯 PHP CLI 即可运行；与 worker 并存时，
 * 因 saveRows 使用 INSERT OR IGNORE 去重，同一期只会在「首次抓到」时推送一次，不会重复推送。
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib.php';

echo date('Y-m-d H:i:s') . " fetch_and_notify 启动\n";
fetchLatest('ssq');
fetchLatest('dlt');
echo date('Y-m-d H:i:s') . " fetch_and_notify 结束\n";
