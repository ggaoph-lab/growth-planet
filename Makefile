COMPOSE := docker compose
APP     := $(COMPOSE) exec app
RUN_APP := $(COMPOSE) run --rm --no-deps app

.PHONY: help init up down build ps logs shell composer artisan key migrate fresh seed-demo \
        test test-backend test-frontend lint typecheck frontend-install frontend-build frontend-dev family

help:
	@echo "make init            生成本地 .env（随机数据库密码）"
	@echo "make build           构建 PHP 镜像"
	@echo "make up / down       启动 / 停止容器"
	@echo "make composer ARGS=  在容器中执行 composer"
	@echo "make key             生成 APP_KEY"
	@echo "make migrate         执行数据库迁移"
	@echo "make family          交互式创建家庭与首位家长"
	@echo "make seed-demo       写入明确标注的测试数据（仅本地）"
	@echo "make test            运行后端与前端全部测试"
	@echo "make frontend-build  构建前端到 frontend/dist（由 nginx 提供）"
	@echo "make frontend-dev    启动 Vite 开发服务器 http://localhost:5173"

init:
	bash deploy/scripts/init-dev.sh

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

frontend-dev:
	cd frontend && npm run dev
