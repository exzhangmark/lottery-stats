#!/bin/bash
# 启动脚本：先后台启动 Workerman 抓取 Worker，再提示访问前端
cd "$(dirname "$0")"

echo "==> 安装依赖"
composer install --no-interaction 2>/dev/null || echo "（composer 未安装则跳过，需手动安装 workerman）"

echo "==> 启动抓取 Worker（守护模式）"
php worker/lottery_worker.php start -d

echo "==> 抓取 Worker 已在后台运行"
echo "    日志请查看 worker 进程输出 / nohup 文件"
echo "==> 请确保 Nginx + PHP-FPM 已按 deploy/nginx.conf 配置并指向 public 目录"
echo "    浏览器访问 http://你的域名/ 即可查看双色球 / 大乐透统计"
