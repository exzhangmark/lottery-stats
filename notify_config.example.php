<?php
/**
 * 开奖推送配置（息知 / 个人微信）
 *
 * 用法：
 *   1. 打开 https://xizhi.qqoq.net 注册并登录，在「发送消息」页复制你的 key（形如 XZd...）
 *   2. 复制本文件为真实配置：  cp notify_config.example.php notify_config.php
 *   3. 把下面 owner_key 填上，enabled 改为 true
 *   4. 重启 worker 生效：  php worker/lottery_worker.php restart
 *
 * 推送时机：
 *   ① 开奖结果：worker 每 10 分钟轮询，一旦抓到「新增开奖期」就推送给 owner + 所有网站订阅者；
 *   ② 选号建议：开奖日 12:00，按各自偏好方案生成号码推送给 owner + 订阅者（meta 表防当日重复）。
 *
 * 安全：notify_config.php 已加入 .gitignore，不会被提交，owner_key 不会泄露。
 *       本 example 文件可安全提交（不含任何私密信息）。
 */
return [
    'enabled'        => false,   // 改为 true 才真正推送
    'owner_key'      => '',      // 你的息知 key（必填，https://xizhi.qqoq.net/{key}.send）
    'default_scheme' => 'cold',  // owner 在「开奖日选号建议」中默认使用的方案（cold/hot/mixed/omit/balance/avg/repeat/lucky/flat）
];
