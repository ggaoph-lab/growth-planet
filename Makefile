COMPOSE      := docker compose
COMPOSE_PROD := docker compose -f compose.prod.yaml --env-file deploy/compose/.env.prod
COMPOSE_PROD_BUILD := $(COMPOSE_PROD) -f compose.build.yaml
# Local preview: prod stack + bind-mount host frontend/dist so 8088 picks up rebuilds.
COMPOSE_PREVIEW := $(COMPOSE_PROD) -f compose.preview.yaml
APP          := $(COMPOSE) exec app
RUN_APP      := $(COMPOSE) run --rm --no-deps app
PROD_APP     := $(COMPOSE_PROD) exec app

.PHONY: help init up down build ps logs shell composer artisan key migrate fresh seed-demo \
        test test-backend test-frontend lint typecheck frontend-install frontend-build frontend-dev preview family \
        db-password-sync \
        prod-init prod-check prod-pull prod-build prod-build-host-frontend prod-up prod-down prod-ps prod-logs \
        prod-migrate prod-artisan prod-preflight prod-family prod-backup

help:
	@echo "Development"
	@echo "  make init            生成本地 .env（已有则保留密码；同步 backend/.env DB_PASSWORD）"
	@echo "  make build           构建 PHP 开发镜像"
	@echo "  make up / down       启动 / 停止开发容器"
	@echo "  make db-password-sync 把运行中 MySQL 用户密码改成与 .env 一致（不丢数据）"
	@echo "  make migrate         执行数据库迁移"
	@echo "  make family          交互式创建家庭与首位家长"
	@echo "  make test            运行后端与前端全部测试"
	@echo "  make frontend-build  构建前端到 frontend/dist"
	@echo "  make preview         构建前端并把 dist 挂到 nginx（预览固定 http://localhost:8088）"
	@echo "  make frontend-dev    监听前端改动并写入 dist（需先 make preview）"
	@echo ""
	@echo "Production (GHCR pull / compose.prod.yaml)"
	@echo "  make prod-init       生成 deploy/compose/.env.prod"
	@echo "  make prod-check      主机兼容性检查"
	@echo "  make prod-pull       拉取 GHCR 预构建镜像"
	@echo "  make prod-build      本地构建生产镜像（调试；日常请用 CI + prod-pull）"
	@echo "  make prod-up/down    启动 / 停止生产容器"
	@echo "  make prod-migrate    执行 migrate --force"
	@echo "  make prod-preflight  生产配置预检"
	@echo "  make prod-family     创建首位家庭/家长"
	@echo "  make prod-backup     加密备份数据库与附件"

init:
	bash deploy/scripts/init-dev.sh

db-password-sync:
	bash deploy/scripts/sync-dev-mysql-password.sh

build:
	$(COMPOSE) build app

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=100

shell:
	$(APP) bash

composer:
	$(RUN_APP) composer $(ARGS)

artisan:
	$(APP) php artisan $(ARGS)

key:
	$(RUN_APP) php artisan key:generate

migrate:
	$(APP) php artisan migrate

fresh:
	$(APP) php artisan migrate:fresh

family:
	$(COMPOSE) exec -it app php artisan family:create

seed-demo:
	$(APP) php artisan db:seed --class=Database\\Seeders\\DemoSeeder

test: test-backend test-frontend

test-backend:
	$(APP) php artisan test

lint:
	$(APP) ./vendor/bin/pint --test
	cd frontend && npm run lint

typecheck:
	cd frontend && npm run typecheck

test-frontend:
	cd frontend && npm run test -- --run

frontend-install:
	cd frontend && npm install

frontend-build:
	cd frontend && npm run build

# Build SPA and mount ./frontend/dist into the running nginx (fixes prod-image-baked frontend).
# After this, http://localhost:8088 always serves your local dist.
# Cursor hooks also call .cursor/hooks/frontend-preview.sh after frontend edits.
preview:
	FORCE=1 .cursor/hooks/frontend-preview.sh now force
	@echo "Preview ready: http://localhost:8088"

# Watch-rebuild into frontend/dist. Run `make preview` once first so nginx mounts dist.
frontend-dev:
	cd frontend && npm run dev

# ---- Production -------------------------------------------------------------

prod-init:
	bash deploy/scripts/init-prod.sh

prod-check:
	bash deploy/scripts/check-host.sh

prod-pull:
	$(COMPOSE_PROD) pull app nginx

prod-build:
	mkdir -p deploy/certs
	$(COMPOSE_PROD_BUILD) build

# If Docker Hub cannot pull node:*, build the SPA on the host then:
#   make frontend-install frontend-build
#   make prod-build-host-frontend
prod-build-host-frontend:
	mkdir -p deploy/certs
	$(COMPOSE_PROD_BUILD) build app
	FRONTEND_DIST=host $(COMPOSE_PROD_BUILD) build nginx

prod-up:
	mkdir -p deploy/certs backups
	$(COMPOSE_PROD) up -d

prod-down:
	$(COMPOSE_PROD) down

prod-ps:
	$(COMPOSE_PROD) ps

prod-logs:
	$(COMPOSE_PROD) logs -f --tail=200

prod-migrate:
	$(PROD_APP) php artisan migrate --force

prod-artisan:
	$(PROD_APP) php artisan $(ARGS)

prod-preflight:
	$(PROD_APP) php artisan app:preflight

prod-family:
	$(COMPOSE_PROD) exec -it app php artisan family:create

prod-backup:
	bash deploy/scripts/backup.sh
