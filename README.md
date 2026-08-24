# 彩票开奖统计工具

读取**福利彩票双色球**与**体育彩票大乐透**开奖结果，存入数据库并按号码出现次数可视化展示。

## 技术栈
- PHP 8.1+（CLI Worker + PHP-FPM 接口）
- Workerman（后台定时抓取）
- Nginx（静态页 + PHP 接口）
- SQLite（零配置数据库）

## 目录结构
```
lottery-stats/
├── api/index.php          # HTTP 数据接口（list / stats）
├── worker/
│   └── lottery_worker.php # Workerman 抓取 Worker（双 API 容错 + 失败 1h 重试）
├── public/index.html      # 前端展示页（双色球 / 大乐透 双板块）
├── data/                  # SQLite 数据库（自动生成，已 gitignore）
├── deploy/
│   ├── nginx.conf         # Nginx 配置示例
│   └── Dockerfile         # PHP-FPM 镜像
├── composer.json
└── README.md
```

## 数据来源（两个免费 API，互为备份）
1. 主用：<http://api.huiniao.top> — `lotteryHistory?type=ssq|dlt`
2. 备用：<https://www.caipiaodate.com> — `void.do?code=ssq|dlt`

抓取策略：依次尝试主用 / 备用 API，任一成功即写入；若两个都失败，则 **1 小时后自动重试**。
正常轮询间隔 10 分钟。

## 部署步骤

### 1. 安装依赖
```bash
composer install
```

### 2. 启动后台抓取 Worker（常驻进程）
```bash
php worker/lottery_worker.php start
# 守护进程： php worker/lottery_worker.php start -d
```

### 3. 配置 Nginx（参考 deploy/nginx.conf）
将 `root` 指向项目 `public` 目录，PHP 请求转发到 PHP-FPM（9000 端口）。
重载 Nginx 后访问 `http://你的域名/` 即可看到页面。

### 4. Docker 部署（可选）
```bash
docker build -t lottery-stats -f deploy/Dockerfile .
docker run -d -p 9000:9000 lottery-stats
```

## 前端说明
- 顶部两个 Tab：**福利双色球** / **体彩大乐透**，点击切换并懒加载对应数据。
- 双色球：33 个红球（1-33）+ 16 个篮球（1-16），球上方显示该数字出现次数。
- 大乐透：35 个红球（1-35）+ 12 个篮球（1-12）。
- 下方表格按最新期号倒序展示开奖列表。

## 接口说明
- `GET /api/index.php?action=list&type=ssq`  → 开奖列表
- `GET /api/index.php?action=stats&type=ssq` → 各号码出现次数（redCount / blueCount）

`type` 取值：`ssq`（双色球）| `dlt`（大乐透）
