<?php
/**
 * 开奖日 12:00 选号建议推送（独立脚本）
 *
 * 用法：
 *   php worker/push_reminder.php
 *
 * 典型部署：加到 crontab，每天 12:00 执行一次
 *   0 12 * * * /usr/bin/php /www/wwwroot/lottery/worker/push_reminder.php >> /www/wwwroot/lottery/data/reminder.log 2>&1
 *
 * 说明：
 *   - 仅当今天是双色球(周一/三/日)或大乐透(周二/四/六)开奖日时才推送，周五无开奖自动跳过；
 *   - 推送对象 = 站点 owner（notify_config.php 的 owner_key）+ 网站订阅的访客（subscribers 表）；
 *   - 每个收件人按其偏好方案（owner 用 default_scheme，访客用订阅时选择的 scheme）生成号码；
 *   - 用 meta 表记录「当天已推」，避免同一天重复推送（脚本可安全重复执行）。
 *   - 若 worker 常驻进程已在运行，它也会在 12:00 自行触发，本脚本作为冗余保险。
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib.php';

echo date('Y-m-d H:i:s') . " push_reminder 启动\n";
maybePushReminder();
echo date('Y-m-d H:i:s') . " push_reminder 结束\n";
