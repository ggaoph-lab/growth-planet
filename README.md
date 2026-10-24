# 成长星球 Growth Planet

面向小学一年级儿童及家长的家庭成长管理系统：任务与习惯、XP 与金币账本、奖励商城、统一审核中心、书包星球、勇敢探索、食物探索日记、电影星球、英语探索星球、科学小百科星球、统一屏幕时间与成长报告。

> 当前阶段：**第六阶段已完成**；**阶段 4.1（电影系列折叠与管理）已实现**。界面默认 **中英双语**（`中文 / English`），右上角可切换「中英双语 / 中文 / English」。各阶段进度见 [`docs/development-plan.md`](docs/development-plan.md)。

## 技术栈

Vue 3 + TypeScript + Vite + Pinia + Vue Router + Element Plus（家长端）｜Laravel 12（容器内 PHP 8.4）+ Sanctum SPA 会话｜MySQL 8.4 LTS｜Nginx｜Docker Compose

## 本地启动

前置：Docker（含 Compose v2）、Node.js 20+、make。

```bash
make init              # 生成 .env 与 backend/.env（随机数据库密码，不入库）
make build             # 构建 PHP 镜像
make up                # 启动 mysql / app / nginx
make composer ARGS=install
make key               # 生成 APP_KEY（仅首次）
make migrate
make frontend-install
make frontend-build    # 构建到 frontend/dist，由 nginx 提供
```

**预览地址固定为** <http://localhost:8088>（不要用 5173）。

如果你在跑生产 compose（`compose.prod.yaml`），前端默认打在镜像里。本地预览固定：

**http://localhost:8088**

Agent 改完 `frontend/` 后会经 Cursor hooks 自动构建并挂到 nginx；你只需刷新 8088。也可手动：

```bash
make preview         # 构建 dist + 挂到 nginx
```

创建家庭与首位家长（交互式输入密码，至少 10 位，系统不内置任何默认密码）：

```bash
make family
```

给亲戚开户：先把你的家长账号授成站点管理员，再在家长后台「家庭设置 → 邀请码」生成一次性码，对方打开 `/register` 自助注册。

```bash
docker compose exec app php artisan site-admin:grant your_username
# 生产：make prod-artisan ARGS='site-admin:grant your_username'
```

只想快速体验时，可以写入明确标注为“测试”的演示数据（生产环境拒绝执行；家长密码和孩子 PIN 随机生成，只在终端显示一次）：

```bash
make seed-demo
```

| 端口 | 用途 |
|---|---|
| **8088** | **唯一预览入口**（Nginx：SPA + API），可在 `.env` / `.env.prod` 的 `HTTP_PORT` 修改 |
| 33061 | MySQL，仅绑定 127.0.0.1，可在 `MYSQL_HOST_PORT` 修改 |

> 无法访问 Docker Hub 时，可先从镜像源拉取 `mysql:8.4`、`php:8.4-fpm-bookworm` 并 `docker tag` 为原名；PHP 基础镜像也可通过 `.env` 的 `PHP_BASE` 指定。

## 首次使用流程

1. 家长登录 → “孩子档案”新建孩子（昵称、内置头像、生日、年级、可选 4-6 位 PIN）。
2. 在孩子要使用的平板/手机上用家长账号登录 → “设备管理”授权当前设备（需再次输入家长密码）。
3. 点“切换到孩子模式” → 孩子点头像、输入 PIN → 进入星球首页。

## 测试与检查

```bash
make test        # 后端 PHPUnit（独立测试库 growth_planet_test）+ 前端 Vitest
make lint        # Pint + ESLint
make typecheck   # vue-tsc
```

## 生产部署（群晖 / Docker）

镜像由 GitHub Actions 推送到 GHCR；NAS 上日常只需：

```bash
bash deploy/scripts/nas-update-from-github.sh   # sync + pull + up + migrate
```

详见 [deployment](docs/deployment.md)。

## 文档

| 文档 | 内容 |
|---|---|
| [architecture](docs/architecture.md) | 模块划分、认证模型、时间与时区、第三方集成边界 |
| [database](docs/database.md) | 数据表设计 |
| [api](docs/api.md) | 接口约定与清单 |
| [security](docs/security.md) | 权限、隐私、内容安全、密钥管理 |
| [deployment](docs/deployment.md) | 群晖 Docker、GHCR 更新与回滚 |
| [development-plan](docs/development-plan.md) | 分阶段计划、验收标准、待家长确认的问题 |

`prd.md`、`points-rules.md`、`item-tracking.md`、`test-plan.md`、`deployment.md` 为 V0 规划稿，已被 V1.0 文档取代，仅供参考。
