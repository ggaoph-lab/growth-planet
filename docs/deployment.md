# 成长星球 — 部署方案（V1.3）

> 推荐生产环境：**群晖 DS918+（Container Manager）+ Docker Compose**。  
> 生产镜像由 **GitHub Actions 构建并推送到 GHCR**；群晖只 **pull → up → migrate**，不再在 NAS 上编译。  
> 开发环境仍使用根目录 `compose.yaml`（源码挂载）。生产配置见 `compose.prod.yaml`（群晖上通常另存为 `compose.yaml` / `docker-compose.yml` 供 Container Manager 读取）。

## 1. 环境总览

| 环境 | 用途 | 方式 |
|---|---|---|
| 开发（macOS / ARM） | 日常开发 | `compose.yaml`：源码挂载、本机 Vite 可选 |
| 生产（推荐） | 家庭使用 | 群晖 + `compose.prod.yaml` + GHCR 预构建镜像 |
| 生产（历史） | Oracle Cloud ARM | 已停用；勿再作为主站写入 |

生产服务：

| 服务 | 说明 |
|---|---|
| `nginx` | `ghcr.io/ggaoph-lab/growth-planet-nginx`（Nginx + 前端构建产物） |
| `app` | `ghcr.io/ggaoph-lab/growth-planet-php`（PHP 8.4-FPM） |
| `worker` | 同 php 镜像：`queue:work` |
| `scheduler` | 同 php 镜像：`schedule:work` |
| `mysql` | `mysql:8.4`，命名卷 `mysql-data`，**不映射到宿主机端口** |
| `backup`（群晖） | 定时加密备份到 `backups/`（若已配置） |

镜像标签（CI 自动推送）：

| 标签 | 含义 |
|---|---|
| `main` / `latest` | `main` 分支最新成功构建 |
| `sha-<short>` | 某一 commit，适合回滚 |
| `1.2.3` | 打了 `v1.2.3` git tag 时的版本 |

当前局域网访问：`http://192.168.66.45:8088`（`HTTP_PORT=8088`，避开 DSM 的 80/443）。

## 2. 群晖生产（DS918+）

### 2.1 前提

- DSM Container Manager 已安装
- 目录：`/volume1/docker/growth-planet`
- 环境文件：`deploy/compose/.env.prod`（权限 `600`，勿提交 Git）
- 端口：宿主机 `8088`/`8443`（或自选未占用端口）
- **GHCR 可读**：将仓库 Packages（`growth-planet-php` / `growth-planet-nginx`）设为 **Public**，或在 NAS 上：

```bash
# PAT 权限：read:packages（只读即可）
echo 'YOUR_GITHUB_PAT' | docker login ghcr.io -u YOUR_GITHUB_USER --password-stdin
```

首次从旧版（NAS 本地 build）切换时，在 `.env.prod` 增加（或确认示例中的默认值）：

```bash
PHP_IMAGE=ghcr.io/ggaoph-lab/growth-planet-php:main
NGINX_IMAGE=ghcr.io/ggaoph-lab/growth-planet-nginx:main
```


### 2.2 日常更新（推荐）

1. 本机改完代码 → 推到 GitHub `main`
2. 等待 Actions 工作流 **Docker images** 成功（构建 amd64 + arm64 并推送 GHCR）
3. 在 NAS 上执行：

```bash
cd /volume1/docker/growth-planet
bash deploy/scripts/nas-update-from-github.sh
```

脚本会：

1. 下载 `ggaoph-lab/growth-planet` 的 `main` 归档
2. 同步 compose / 脚本 / 挂载用配置，并保留 `.env.prod`、`backups/`、证书
3. **`docker compose pull`** 拉取预构建镜像（不再在 NAS 上 `build`）
4. 重启容器并执行 `migrate` / `app:preflight`

若当前用户无 Docker 权限：只同步代码，并提示在 Container Manager 中 Pull / Start。

**应急**（GHCR 不可用、必须离线）：`LOCAL_BUILD=1 bash deploy/scripts/nas-update-from-github.sh`（慢，等同旧流程）。

### 2.3 回滚

在 `deploy/compose/.env.prod` 把镜像钉到已知好的标签，再更新：

```bash
PHP_IMAGE=ghcr.io/ggaoph-lab/growth-planet-php:sha-abcdef0
NGINX_IMAGE=ghcr.io/ggaoph-lab/growth-planet-nginx:sha-abcdef0
```

然后重新执行 `nas-update-from-github.sh`，或仅：

```bash
docker compose -f compose.yaml --env-file deploy/compose/.env.prod pull
docker compose -f compose.yaml --env-file deploy/compose/.env.prod up -d
docker compose -f compose.yaml --env-file deploy/compose/.env.prod exec -T app php artisan migrate --force
```

### 2.4 备份与恢复

- 群晖备份脚本：`deploy/scripts/nas-backup.sh`（若存在；否则见 `deploy/scripts/backup.sh`）
- 输出：`backups/growth-planet-*.tar.enc`
- 恢复：`bash deploy/scripts/restore.sh <file>`（或文档中的 nas-restore 等价流程）

**不要**把未加密备份同步到不受控的第三方网盘。

### 2.5 域名与 HTTPS（可选）

局域网可先用 `http://192.168.66.45:8088`。若要用原域名：

1. 在 DSM「控制面板 → 登录门户 → 高级 → 反向代理」将域名指到 `127.0.0.1:8088`
2. 更新 `deploy/compose/.env.prod` 的 `APP_URL` 与 `SANCTUM_STATEFUL_DOMAINS`
3. DNS / Cloudflare 指向群晖公网入口；确认原甲骨文源站已停用，避免双写

## 3. CI 镜像流水线

工作流：`.github/workflows/docker-images.yml`

| 触发 | 行为 |
|---|---|
| push `main`（相关路径变更） | 构建并推送 `php` + `nginx`（`main` / `latest` / `sha-*`） |
| push tag `v*` | 额外推送 semver 标签 |
| `workflow_dispatch` | 手动重跑 |

平台：`linux/amd64`（群晖 DS918+）、`linux/arm64`（备用）。

本机强制本地构建（调试用）：

```bash
make prod-build   # 使用 compose.build.yaml
make prod-up
```

## 4. 开发环境（macOS）

```bash
make init && make build && make up
make composer ARGS=install
make key && make migrate
make frontend-install && make frontend-build
```

访问 <http://localhost:8088>。

本地栈项目名为 `growth-planet-dev`，MySQL 卷为 `growth-planet_dev_mysql_data`，与 `compose.prod.yaml`（`make preview` / NAS）隔离，避免两套 `.env` 密码抢同一数据目录。`make init` 不会覆盖已有密码，只会把根目录 `.env` 的 `MYSQL_PASSWORD` 同步到 `backend/.env`。若仍出现 Access denied：`make db-password-sync`。

## 5. 运维命令（本机 / 通用 Compose）

| 命令 | 作用 |
|---|---|
| `make prod-ps` / `make prod-logs` | 状态 / 日志 |
| `make prod-artisan ARGS="..."` | 任意 artisan |
| `make prod-preflight` | 安全与连通性预检 |
| `make prod-backup` | 立即备份 |
| `bash deploy/scripts/restore.sh <file>` | 恢复 |

## 6. 安全要点（生产）

- `APP_DEBUG=false`，`APP_ENV=production`
- MySQL **不**映射宿主机端口；仅容器网络访问
- `.env.prod` 权限 `600`，不入库
- `DemoSeeder` 在生产环境拒绝执行
- Nginx：`server_tokens off`、CSP / 安全头；HTTPS 时 HSTS
- `app:preflight` 检查密钥、调试开关、库连通与迁移表
- GHCR 若保持私有：NAS 仅用 `read:packages` PAT，勿把写权限 token 放进脚本仓库

## 7. 已知限制

- Playwright 全设备矩阵、导出/删除孩子数据等增强项见 `docs/development-plan.md`；核心六阶段业务模块已实现并可运行
- 群晖上可用 `CACHE_STORE=file`、`SESSION_DRIVER=file`（若改用文件缓存，需自行保证多容器共享卷；默认库驱动即可）
- 英语星球不接入视频平台；TMDB 未配置时电影仅支持手动录入
- 纯局域网 HTTP 没有浏览器安全上下文，录音/麦克风与 Service Worker 仍需要可信 HTTPS
- 首次启用本方案前须等 CI 至少成功推送一次镜像；在此之前可用 `LOCAL_BUILD=1`
