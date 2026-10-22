# 成长星球 — 部署方案（V1.2）

> 推荐生产环境：**群晖 DS918+（Container Manager）+ Docker Compose**。  
> 开发环境仍使用根目录 `compose.yaml`（源码挂载）。生产配置见 `compose.prod.yaml`（群晖上通常另存为 `compose.yaml` / `docker-compose.yml` 供 Container Manager 读取）。

## 1. 环境总览

| 环境 | 用途 | 方式 |
|---|---|---|
| 开发（macOS / ARM） | 日常开发 | `compose.yaml`：源码挂载、本机 Vite 可选 |
| 生产（推荐） | 家庭使用 | 群晖 Container Manager + `compose.prod.yaml`（目录 `/volume1/docker/growth-planet`） |
| 生产（历史） | Oracle Cloud ARM | 已停用；勿再作为主站写入 |

生产服务：

| 服务 | 说明 |
|---|---|
| `nginx` | 自建镜像：Nginx + 前端构建产物；`/healthz`；可选 TLS |
| `app` | 自建 `growth-planet-php:prod`（PHP 8.4-FPM） |
| `worker` | 同 app 镜像：`queue:work` |
| `scheduler` | 同 app 镜像：`schedule:work` |
| `mysql` | `mysql:8.4`，命名卷 `mysql-data`，**不映射到宿主机端口** |
| `backup`（群晖） | 定时加密备份到 `backups/` |

当前局域网访问：`http://192.168.66.45:8088`（`HTTP_PORT=8088`，避开 DSM 的 80/443）。

## 2. 群晖生产（DS918+）

### 2.1 前提

- DSM Container Manager 已安装
- 目录：`/volume1/docker/growth-planet`
- 环境文件：`deploy/compose/.env.prod`（权限 `600`，勿提交 Git）
- 端口：宿主机 `8088`/`8443`（或自选未占用端口），需要公网域名时再用 DSM 反向代理到该端口

### 2.2 从 GitHub 更新

代码以 GitHub `main` 为准。在 NAS 上（需能使用 Docker，或更新代码后在 Container Manager 里重建）：

```bash
cd /volume1/docker/growth-planet
bash deploy/scripts/nas-update-from-github.sh
```

脚本会：

1. 下载 `ggaoph-lab/growth-planet` 的 `main` 归档
2. 同步代码并保留 `.env.prod`、`backups/`、证书等本地文件
3. 若当前用户能访问 Docker：构建镜像、重启容器、执行 `migrate`
4. 若无 Docker 权限：只同步代码，并提示在 Container Manager 中 Build / Start

日常开发流程：本机改完 → 推到 GitHub `main` → 在群晖执行上述更新脚本。

### 2.3 备份与恢复

- 群晖备份脚本：`deploy/scripts/nas-backup.sh`（由 `backup` 服务调用）
- 输出：`backups/growth-planet-*.tar.enc`
- 恢复（在 backup 容器内或等价环境）：`bash deploy/scripts/nas-restore.sh /backups/oracle-final-*.tar.enc`

**不要**把未加密备份同步到不受控的第三方网盘。

### 2.4 域名与 HTTPS（可选）

局域网可先用 `http://192.168.66.45:8088`。若要用原域名：

1. 在 DSM「控制面板 → 登录门户 → 高级 → 反向代理」将 `growth.gkgkgo.online` 指到 `127.0.0.1:8088`
2. 更新 `deploy/compose/.env.prod` 的 `APP_URL` 与 `SANCTUM_STATEFUL_DOMAINS`
3. DNS / Cloudflare 指向群晖公网入口；确认原甲骨文源站已停用，避免双写

## 3. 开发环境（macOS）

```bash
make init && make build && make up
make composer ARGS=install
make key && make migrate
make frontend-install && make frontend-build
```

访问 <http://localhost:8088>。

## 4. 运维命令（本机 / 通用 Compose）

| 命令 | 作用 |
|---|---|
| `make prod-ps` / `make prod-logs` | 状态 / 日志 |
| `make prod-artisan ARGS="..."` | 任意 artisan |
| `make prod-preflight` | 安全与连通性预检 |
| `make prod-backup` | 立即备份 |
| `bash deploy/scripts/restore.sh <file>` | 恢复 |

## 5. 安全要点（生产）

- `APP_DEBUG=false`，`APP_ENV=production`
- MySQL **不**映射宿主机端口；仅容器网络访问
- `.env.prod` 权限 `600`，不入库
- `DemoSeeder` 在生产环境拒绝执行
- Nginx：`server_tokens off`、CSP / 安全头；HTTPS 时 HSTS
- `app:preflight` 检查密钥、调试开关、库连通与迁移表

## 6. 已知限制

- Playwright 全设备矩阵、导出/删除孩子数据等增强项见 `docs/development-plan.md`；核心六阶段业务模块已实现并可运行
- 群晖上常用 `CACHE_STORE=file`、`SESSION_DRIVER=file`（`app-runtime` 卷共享）
- 英语星球不接入视频平台；TMDB 未配置时电影仅支持手动录入
- 纯局域网 HTTP 没有浏览器安全上下文，录音/麦克风与 Service Worker 仍需要可信 HTTPS
