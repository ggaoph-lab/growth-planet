> ⚠️ **V0 规划文档**（2026-10-11 首版需求）。已被 V1.0 需求部分取代：与 `architecture.md`、`database.md`、`api.md`、`security.md`、`development-plan.md` 冲突之处以 V1.0 文档为准；本文件将在对应开发阶段更新。

# 成长星球 — 部署方案

> 推荐：**Rocky Linux 9 + Docker Compose**。CentOS 7 仅作为过渡兼容目标，见 §5。

## 1. 环境总览

| 环境 | 用途 | 方式 |
|---|---|---|
| 开发（macOS） | 日常开发 | Docker Compose（`compose.dev.yml`）：后端源码挂载、Vite 开发服务器在本机运行 |
| 测试 | 自动化测试 | Compose 中的 `mysql` 服务 + `growth_planet_test` 库 |
| 生产（推荐） | 家庭使用 | Rocky Linux 9 + Docker Compose（`compose.prod.yml`） |
| 生产（备选 A） | 无法使用 Docker 时 | Rocky Linux 9 非容器部署（Nginx + PHP-FPM + MySQL + systemd） |
| 生产（过渡） | 现有 CentOS 7 服务器 | Docker Compose，先运行兼容性检查脚本 |

## 2. 开发环境（macOS）

当前机器状态（2026-10-11 检查）：已安装 Docker 29、Node 26、Git；**未安装 PHP、Composer、MySQL**。因此 PHP 与 MySQL 全部运行在容器中，无需本机安装。

```bash
cd growth-planet
cp backend/.env.example backend/.env       # 填写本地开发用密码（不提交）
make up                                    # 启动 mysql、app、nginx、worker、scheduler
make composer ARGS="install"
make artisan ARGS="key:generate"
make artisan ARGS="migrate --seed"
make artisan ARGS="family:create"          # 交互式创建家庭和家长账号
cd frontend && npm install && npm run dev  # Vite 开发服务器（代理 /api 到 nginx）
```

Apple Silicon 注意：所有镜像使用多架构官方镜像（`php:8.3-fpm-bookworm`、`mysql:8.4`、`nginx:1.26-alpine`），不需要 `platform: linux/amd64`。

## 3. Docker Compose 生产部署

### 3.1 服务

| 服务 | 镜像 | 说明 |
|---|---|---|
| `nginx` | 自建（nginx + 前端构建产物） | 80/443；静态文件、`/api` 与 `/sanctum` 转发到 `app:9000`；安全头 |
| `app` | 自建 `growth-planet-php`（php-fpm 8.3 + 扩展：pdo_mysql、intl、gd/imagick、zip、bcmath、opcache） | 只读根文件系统（`storage` 挂卷） |
| `worker` | 同 `app` | `php artisan queue:work --tries=3 --max-time=3600` |
| `scheduler` | 同 `app` | `php artisan schedule:work` |
| `mysql` | `mysql:8.4` | `--character-set-server=utf8mb4 --collation-server=utf8mb4_0900_ai_ci --default-time-zone=+00:00`；数据卷；不暴露端口到公网 |

### 3.2 部署步骤

```bash
# 服务器上
git clone <repo> /opt/growth-planet && cd /opt/growth-planet
cp deploy/compose/.env.prod.example deploy/compose/.env.prod   # 填写强密码，chmod 600
bash deploy/scripts/check-host.sh                               # 兼容性检查
make prod-build
make prod-up
make prod-artisan ARGS="migrate --force"
make prod-artisan ARGS="app:preflight"
make prod-artisan ARGS="family:create"
```

可选：在 `deploy/compose/.env.prod` 中设置 `TMDB_API_KEY`，家长端才可使用 TMDB 检索；未配置时仅支持手动录入电影。前端 PWA 清单与 Service Worker 随 `make frontend-build` 进入 `frontend/dist`（`manifest.webmanifest`、`sw.js`），Nginx 需正常提供静态文件（默认已包含）。

### 3.3 HTTPS

- 已有域名：使用 acme.sh 申请证书，挂载到 nginx 容器 `/etc/nginx/certs`。
- 仅家庭内网使用：可用自签证书或内网 HTTP（PWA 安装与 Service Worker 需要 HTTPS 或 localhost；内网建议使用 mkcert 生成本地 CA）。

### 3.4 定时备份

宿主机 crontab：

```
0 3 * * * cd /opt/growth-planet && bash deploy/scripts/backup.sh >> /var/log/growth-planet-backup.log 2>&1
```

备份目录建议放在单独磁盘或同步到另一台自有设备（NAS），**不要同步到不受控的第三方网盘**；如必须异地，只上传加密后的文件。

### 3.5 升级

```bash
git pull
bash deploy/scripts/backup.sh       # 升级前强制备份
make prod-build && make prod-up
make prod-artisan ARGS="migrate --force"
```

## 4. 非容器部署（Rocky Linux 9）

```bash
dnf install -y epel-release
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf module reset php -y && dnf module enable php:remi-8.3 -y
dnf install -y php-fpm php-cli php-mysqlnd php-intl php-gd php-zip php-bcmath php-opcache php-mbstring php-xml
dnf install -y nginx
# MySQL 8.4 LTS：使用 MySQL 官方 Yum 仓库（mysql84-community-release-el9）
dnf install -y mysql-community-server
```

- `deploy/baremetal/` 提供：`nginx.conf`、`php-fpm.d/growth-planet.conf`、`growth-planet-worker.service`、`growth-planet-scheduler.service`（或 crontab `* * * * * php artisan schedule:run`）。
- SELinux 保持 Enforcing：`setsebool -P httpd_can_network_connect_db 1`，为 `storage/` 设置 `httpd_sys_rw_content_t` 上下文。
- 防火墙：`firewall-cmd --add-service=https --permanent`；MySQL 只监听 127.0.0.1。
- 前端在构建机（macOS）执行 `npm run build`，将 `frontend/dist` 上传到服务器。

## 5. CentOS 7 兼容性与风险

### 5.1 现状

- CentOS 7 已于 **2024-06-30 停止维护**：不再有安全更新，官方 yum 源已迁移到 vault。
- 内核 3.10、glibc 2.17、OpenSSL 1.0.2：无法直接安装 PHP 8.2+（官方仓库没有；Remi 对 EL7 的支持也已结束）。
- Docker 官方对 CentOS 7 的软件包已停止更新（最后可用版本系列为 26.1.x）。

**结论**：CentOS 7 上**只支持容器化部署**，不提供非容器方案。PHP、MySQL 的版本由容器提供，不依赖宿主机库。

### 5.2 兼容性验证（`deploy/scripts/check-host.sh`）

脚本检查并输出 PASS/WARN/FAIL：

| 检查项 | 要求 |
|---|---|
| 操作系统 | 识别 CentOS 7 → WARN（停止维护）；Rocky/Alma 9 → PASS |
| 内核 | ≥ 3.10（CentOS 7 最低）；≥ 5.14 PASS |
| Docker 版本 | ≥ 20.10.10（更早版本 seccomp 配置会拦截新版 glibc 的 `clone3`，导致 bookworm 镜像内进程异常） |
| Compose v2 | `docker compose version` 可用 |
| 存储驱动 | `overlay2`；底层 XFS 需 `ftype=1`（`xfs_info` 检查 d_type） |
| 时间同步 | chronyd/ntpd 运行中，时钟偏差 < 1s |
| 磁盘空间 | ≥ 10GB 可用 |
| 内存 | ≥ 2GB（MySQL 8.4 建议） |
| 端口 | 80/443 未被占用 |
| 冒烟测试 | 拉起临时 `mysql:8.4` 与 `php:8.3-cli` 容器，执行 `SELECT 1`、`php -r 'echo date_default_timezone_get();'`、utf8mb4 表情符号读写 |

### 5.3 CentOS 7 上的 Docker 安装

```bash
# 官方源已归档，需将 CentOS-Base 指向 vault.centos.org；然后：
yum install -y yum-utils
yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
yum install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable --now docker
```

## 6. 迁移到 Rocky Linux 9（推荐尽快执行）

EL7 到 EL9 **不支持原地升级**（ELevate/Leapp 需 7→8→9 两跳，风险高、易中断）。推荐**新机 + 备份恢复**：

1. 准备 Rocky Linux 9 服务器（新机器或新虚拟机），运行 `check-host.sh`。
2. 安装 Docker（`dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo`）。
3. 部署同版本代码（`git checkout <当前生产 tag>`），`make prod-up`（空库）。
4. 在 CentOS 7 上：进入维护模式（`php artisan down`），执行 `backup.sh`。
5. 将加密备份传到新服务器（`scp`），执行 `restore.sh`。
6. 运行 `points:reconcile`、`items:rebuild-projection --verify`、`app:preflight`。
7. 切换 DNS / 内网 IP；观察 1 周后下线旧服务器（保留最后一份备份）。

预计停机时间：家庭数据量下 < 15 分钟。

## 7. 运维命令速查

| 命令 | 作用 |
|---|---|
| `make prod-logs` | 查看容器日志 |
| `make prod-artisan ARGS="points:reconcile"` | 积分对账 |
| `make prod-artisan ARGS="items:rebuild-projection --verify"` | 物品状态校验 |
| `bash deploy/scripts/backup.sh` | 立即备份 |
| `bash deploy/scripts/restore.sh <file>` | 恢复（需二次确认输入 `RESTORE`） |
| `bash deploy/scripts/verify-restore.sh` | 在临时容器中演练恢复 |
| `make prod-artisan ARGS="down"` / `up` | 维护模式 |
