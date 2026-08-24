#!/usr/bin/env bash
# 彩票开奖统计 —— Ubuntu/Debian 一键部署脚本
# 用法（在服务器上，以 root 执行）：
#   sudo bash setup.sh
# 或克隆后执行：
#   curl -sSL https://raw.githubusercontent.com/exzhangmark/lottery-stats/master/deploy/setup.sh | sudo bash
set -euo pipefail

echo "=============================================="
echo " 彩票开奖统计 一键部署 (Ubuntu / Debian)"
echo "=============================================="

if [ "$(id -u)" -ne 0 ]; then
  echo "错误：请使用 root 运行（ sudo bash setup.sh ）" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
INSTALL_DIR=/www/lottery

echo "[1/8] 更新软件源并安装依赖 (nginx/php/sqlite/git) ..."
apt-get update -y
apt-get install -y nginx php php-fpm php-sqlite3 php-curl php-mbstring php-xml git unzip curl ca-certificates sudo
if php -m | grep -qi sqlite3; then
  echo "pdo_sqlite 已启用"
else
  phpenmod sqlite3 || true
  echo "已尝试启用 sqlite3"
fi

echo "[2/8] 安装 Composer ..."
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
composer --version | head -1

echo "[3/8] 拉取代码 ..."
if [ ! -d "$INSTALL_DIR/.git" ]; then
  mkdir -p /www
  git clone https://github.com/exzhangmark/lottery-stats.git "$INSTALL_DIR"
else
  git -C "$INSTALL_DIR" pull --ff-only
fi

echo "[4/8] 安装 PHP 依赖 (Workerman) ..."
cd "$INSTALL_DIR"
composer install --no-interaction --no-dev 2>&1 | tail -3 || \
  echo "⚠ composer install 失败：常驻抓取 Worker 将不可用，但接口与历史数据不受影响"

echo "[5/8] 配置 PHP-FPM 监听 9000 并赋权 ..."
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
POOL="/etc/php/$PHP_VER/fpm/pool.d/www.conf"
if [ -f "$POOL" ]; then
  sed -i 's#^listen = .*#listen = 127.0.0.1:9000#' "$POOL"
fi
chown -R www-data:www-data "$INSTALL_DIR"
systemctl restart "php${PHP_VER}-fpm"
systemctl enable  "php${PHP_VER}-fpm"

echo "[6/8] 配置 Nginx ..."
cp "$INSTALL_DIR/deploy/nginx.conf" /etc/nginx/sites-available/lottery
ln -sf /etc/nginx/sites-available/lottery /etc/nginx/sites-enabled/lottery
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl restart nginx
systemctl enable nginx

echo "[7/8] 回填 2026-01-01 至今的开奖数据 ..."
su -s /bin/bash www-data -c "cd $INSTALL_DIR && php worker/backfill.php --since=2026-01-01" 2>&1 | tail -20 || \
  echo "⚠ 回填失败，请检查服务器出网 / 数据源可用性"

echo "[8/8] 启动常驻抓取 Worker ..."
su -s /bin/bash www-data -c "cd $INSTALL_DIR && php worker/lottery_worker.php start -d" 2>&1 | tail -5 || \
  echo "⚠ Worker 启动失败（可稍后手动执行： cd $INSTALL_DIR && php worker/lottery_worker.php start -d ）"

echo
SERVER_IP=$(curl -s --max-time 8 https://api.ipify.org || hostname -I | awk '{print $1}')
echo "=============================================="
echo " ✅ 部署完成！"
echo " 访问入口： http://${SERVER_IP}/"
echo " 接口自检： http://${SERVER_IP}/api/index.php?action=list&type=ssq"
echo " 意见列表： http://${SERVER_IP}/api/index.php?action=feedback_list"
echo "=============================================="
