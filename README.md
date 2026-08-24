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
├── api/index.php          # HTTP 数据接口（list / stats / feedback）
├── lib.php                # 公共抓取/解析库（不依赖 Workerman，被 worker 与 backfill 共用）
├── db.php                 # 公共数据层（PDO+SQLite，含意见表与防攻击）
├── worker/
│   ├── lottery_worker.php # Workerman 抓取 Worker（多源容错 + 失败 1h 重试）
│   └── backfill.php       # 历史开奖数据回填脚本（一次性/按需）
├── public/index.html      # 前端展示页（双色球 / 大乐透 双板块 + 意见反馈抽屉）
├── data/                  # SQLite 数据库（自动生成，已 gitignore）
├── deploy/
│   ├── nginx.conf         # Nginx 配置示例
│   └── Dockerfile         # PHP-FPM 镜像
├── composer.json
└── README.md
```

## 数据来源
按优先级依次尝试，**任一成功即采用**：
1. **主用（福彩官方）**：<https://www.cwl.gov.cn> — `findDrawNotice?name=ssq`（仅福利彩票，覆盖双色球）
2. **备用 1**：<http://api.huiniao.top> — `lotteryHistory?type=ssq|dlt`
3. **备用 2**：<https://www.caipiaodate.com> — `void.do?code=ssq|dlt`

> 说明：福彩官网不含体彩大乐透，故大乐透实际主源为 huiniao，cwl 自动跳过回退。
> 任一源抓取失败则顺延下一个；全部失败则 **1 小时后自动重试**。正常轮询间隔 10 分钟。

## 部署步骤

### 方式一：一键部署脚本（推荐，Ubuntu/Debian）
在服务器上以 root 执行：
```bash
curl -sSL https://raw.githubusercontent.com/exzhangmark/lottery-stats/master/deploy/setup.sh | sudo bash
```
脚本会自动：安装 nginx/php/php-sqlite3 → 安装 Composer → 克隆代码 → 配置 PHP-FPM(9000) → 配置 Nginx → **回填 2026-01-01 至今开奖数据** → 启动常驻抓取 Worker，最后打印访问入口（默认 `http://服务器IP/`）。

### 方式二：手动部署
### 1. 安装依赖
```bash
composer install
```

### 2. 启动后台抓取 Worker（常驻进程）
```bash
php worker/lottery_worker.php start
# 守护进程： php worker/lottery_worker.php start -d
```

### 2.5 历史开奖数据回填（可选，一次性）
若想一次性补齐某日期以来的全部历史开奖（例如上线前补 2026 年至今），运行回填脚本：
```bash
# 回填 2026-01-01 至今（双色球 + 大乐透）
php worker/backfill.php

# 自定义起始日期
php worker/backfill.php --since=2025-01-01

# 只回填双色球
php worker/backfill.php --type=ssq
```
脚本行为：
- 对每个彩种选定「第一个能返回数据」的数据源，沿该源分页向后翻（最新 → 最早）。
- 每翻一页随机休眠 **3~5 秒**，模拟正常访问速度，避免被接口限流。
- 仅写入 `open_time >= 起始日期` 的记录；按 `(type, issue)` 去重，重复期号自动忽略。
- 当某页「最新一期」已早于起始日期时自动停止。

> 依赖 `php_pdo_sqlite` 扩展（与前端接口一致）。

### 3. 配置 Nginx（参考 deploy/nginx.conf）
`deploy/nginx.conf` 已将 `root` 指向项目根目录 `/www/lottery`，并把 `/api/` 映射到项目下的 `api/` 目录、根路径回退到 `public/index.html`，PHP 请求转发到 PHP-FPM（9000 端口）。
将 `deploy/nginx.conf` 复制为 `/etc/nginx/sites-available/lottery` 并启用，重载 Nginx 后访问 `http://服务器IP/` 即可看到页面。

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
