# 成长星球 — 部署方案（V1.1）

> 推荐生产环境：**Ubuntu 24.04 LTS（ARM64）+ Docker Compose**，目标平台为 Oracle Cloud Ampere A1。  
> 开发环境仍使用根目录 `compose.yaml`（源码挂载）。生产使用 `compose.prod.yaml`。

## 1. 环境总览

| 环境 | 用途 | 方式 |
|---|---|---|
| 开发（macOS / ARM） | 日常开发 | `compose.yaml`：源码挂载、本机 Vite 可选 |
| 生产（推荐） | 家庭使用 | Ubuntu 24.04 ARM64 + `compose.prod.yaml`（`/opt/growth-planet`） |
| 生产（备选） | x86_64 主机 | 同一套 Compose（官方多架构镜像） |

生产服务：

| 服务 | 说明 |
|---|---|
| `nginx` | 自建镜像：Nginx + 前端构建产物；`/healthz`；可选 TLS |
| `app` | 自建 `growth-planet-php:prod`（PHP 8.4-FPM） |
| `worker` | 同 app 镜像：`queue:work` |
| `scheduler` | 同 app 镜像：`schedule:work` |
| `mysql` | `mysql:8.4`，命名卷 `mysql-data`，**不映射到宿主机端口** |

## 2. 首次部署（Oracle Cloud ARM Ampere A1）

### 2.1 服务器准备

- 镜像：Ubuntu 24.04.5 LTS（aarch64）
- 规格建议：≥ 2 OCPU / 4 GiB（当前 4 OCPU / 23 GiB 充足）
- 安全组放行：入站 TCP `80`、`443`（SSH 仅限你的 IP）
- 生产目录：`/opt/growth-planet`

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git openssl
# Docker Engine + Compose plugin（官方文档）
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"   # 重新登录后生效
```

### 2.2 拉取代码并生成密钥

```bash
sudo mkdir -p /opt/growth-planet
sudo chown "$USER":"$USER" /opt/growth-planet
git clone git@github.com:ggaoph-lab/growth-planet.git /opt/growth-planet
cd /opt/growth-planet

bash deploy/scripts/check-host.sh
make prod-init
chmod 600 deploy/compose/.env.prod
```

编辑 `deploy/compose/.env.prod`：

1. `APP_URL`：公网域名或 `http://<公网IP>`（启用 HTTPS 后改为 `https://...`）
2. `SANCTUM_STATEFUL_DOMAINS`：与浏览器访问的 Host 一致（无协议，如 `example.com` 或公网 IP）
3. 启用 HTTPS 时保持 `SESSION_SECURE_COOKIE=true`；若暂时仅 HTTP，设为 `false`
4. 离线抄写并保管 `APP_KEY` 与 `BACKUP_ENCRYPTION_PASSPHRASE`（丢失 `APP_KEY` 将无法解密孩子生日）

### 2.3 构建、启动、迁移

```bash
make prod-build           # 若无法拉取 node 镜像：先 make frontend-install frontend-build && make prod-build-host-frontend
make prod-up
make prod-migrate
make prod-preflight
make prod-family          # 交互创建首位家庭与家长（无默认密码）
```

验证：

```bash
curl -fsS http://127.0.0.1/healthz          # 前端/Nginx：ok
curl -fsS http://127.0.0.1/api/health       # 后端：status=ok
```

浏览器打开 `APP_URL` → 家长登录 → 创建孩子 → 授权设备 → 孩子 PIN 登录。

### 2.4 HTTPS（推荐）

将证书放到宿主机（已被 `.gitignore`）：

```text
deploy/certs/fullchain.pem
deploy/certs/privkey.pem
```

然后：

```bash
# 确认 APP_URL=https://... 且 SESSION_SECURE_COOKIE=true
make prod-up
```

Nginx 入口脚本检测到证书后自动启用 443，并将 80 重定向到 HTTPS。可用 acme.sh / certbot 在宿主机申请后写入上述路径。

## 3. 备份与恢复

```bash
# 每日 03:00（宿主机 crontab）
0 3 * * * cd /opt/growth-planet && bash deploy/scripts/backup.sh >> /var/log/growth-planet-backup.log 2>&1
```

- 备份输出：`backups/growth-planet-*.tar.enc`（OpenSSL AES-256-CBC + PBKDF2）
- 内容：MySQL 逻辑备份 + `storage/app`
- 恢复：`bash deploy/scripts/restore.sh backups/<file>.tar.enc`（需输入 `RESTORE` 确认）

**不要**把未加密备份同步到不受控的第三方网盘。

## 4. 升级

```bash
cd /opt/growth-planet
bash deploy/scripts/backup.sh
git pull
make prod-build && make prod-up
make prod-migrate
make prod-preflight
```

## 5. 开发环境（macOS）

```bash
make init && make build && make up
make composer ARGS=install
make key && make migrate
make frontend-install && make frontend-build
```

访问 <http://localhost:8088>。Apple Silicon / Oracle ARM 均使用官方多架构基础镜像，无需 `platform: linux/amd64`。

## 6. 运维命令

| 命令 | 作用 |
|---|---|
| `make prod-ps` / `make prod-logs` | 状态 / 日志 |
| `make prod-artisan ARGS="..."` | 任意 artisan |
| `make prod-preflight` | 安全与连通性预检 |
| `make prod-backup` | 立即备份 |
| `bash deploy/scripts/restore.sh <file>` | 恢复 |

## 7. 安全要点（生产）

- `APP_DEBUG=false`，`APP_ENV=production`
- MySQL **不**映射宿主机端口；仅容器网络访问
- `.env.prod` 权限 `600`，不入库
- `DemoSeeder` 在生产环境拒绝执行
- Nginx：`server_tokens off`、CSP / 安全头；HTTPS 时 HSTS
- `app:preflight` 检查密钥、调试开关、库连通与迁移表

## 8. 已知限制

- Playwright 全设备矩阵、导出/删除孩子数据等增强项见 `docs/development-plan.md`；核心六阶段业务模块已实现并可运行
- 无 Redis 时使用 database 驱动做 cache/queue/session（单机家庭部署足够）
- 英语星球不接入视频平台；TMDB 未配置时电影仅支持手动录入
