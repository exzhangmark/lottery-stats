<?php
/**
 * 开奖结果推送配置（PushPlus -> 个人微信）
 *
 * 用法：
 *   1. 打开 https://www.pushplus.plus 注册并登录，在「一键推送」页复制你的 Token
 *   2. 复制本文件为真实配置：  cp notify_config.example.php notify_config.php
 *   3. 把下面 token 填上，并将 enabled 改为 true
 *   4. 重启 worker 生效：  php worker/lottery_worker.php start -d
 *
 * 推送时机：worker 每 10 分钟轮询，一旦抓到「新增开奖期」就推送最新一期到微信。
 *           历史回填（backfill.php）不会触发推送。
 *
 * 安全：notify_config.php 已加入 .gitignore，不会被提交，token 不会泄露。
 *       本 example 文件可安全提交（不含任何私密信息）。
 */
return [
    'enabled' => false,   // 改为 true 才真正推送
    'token'   => '',      // 你的 PushPlus Token（必填）
    // 可选：PushPlus 群组 topic，留空则只推给自己
    'topic'   => '',
];
