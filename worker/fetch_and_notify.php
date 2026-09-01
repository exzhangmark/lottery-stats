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
 * 用法（crontab，仅开奖夜、开奖后窗口运行——白天与周五休市无需空转）：
 *   双色球 周二/四/日(2/4/0)，大乐透 周一/三/六(1/3/6)，开奖约 20:30~21:30，结果 21:00 后陆续公布。
 *   故排期：21~23 点的 10/30/50 分，每周日~周四及周六（排除周五 5），即开奖当晚每 20 分钟探一次：
 *   10,30,50 21-23 0,1,2,3,4,6 * * /usr/bin/php /www/wwwroot/lottery/worker/fetch_and_notify.php >> /www/wwwroot/lottery/data/fetch.log 2>&1
 *   说明：同一期结果因 saveRows 使用 INSERT OR IGNORE 去重，只会在「首次抓到」时推送一次；非开奖夜不跑，完全空转为零。
 *   兜底：网站被访问时 api?action=fetch（5 分钟节流）亦可触发抓取，覆盖偶发漏跑。
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
