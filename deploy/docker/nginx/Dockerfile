# Nginx + built Vue SPA.
# Multi-arch: node and nginx alpine images support linux/arm64 (Oracle Ampere).
#
# Default: build the SPA inside Docker (needs Docker Hub access to NODE_BASE).
# Offline / flaky registry: build on the host (`make frontend-build`) then:
#   docker build --build-arg FRONTEND_DIST=host ...
ARG NODE_BASE=node:22-bookworm
ARG NGINX_BASE=nginx:1.27-alpine
ARG FRONTEND_DIST=docker

FROM ${NODE_BASE} AS frontend-build
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# Host-provided dist (used when FRONTEND_DIST=host).
FROM ${NGINX_BASE} AS frontend-host
COPY frontend/dist /frontend/dist

FROM frontend-build AS frontend-selected-docker
FROM frontend-host AS frontend-selected-host

FROM frontend-selected-${FRONTEND_DIST} AS frontend-selected

FROM ${NGINX_BASE} AS prod

# Keep configs outside /etc/nginx/templates so the stock envsubst hook does not touch them.
COPY deploy/docker/nginx/prod.conf /etc/growth-planet/nginx/prod.conf
COPY deploy/docker/nginx/prod-ssl.conf /etc/growth-planet/nginx/prod-ssl.conf
COPY deploy/docker/nginx/docker-entrypoint.sh /docker-entrypoint.d/40-growth-planet-config.sh
RUN chmod +x /docker-entrypoint.d/40-growth-planet-config.sh \
    && rm -f /etc/nginx/conf.d/default.conf

COPY --from=frontend-selected /frontend/dist /var/www/frontend/dist

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
  CMD wget -qO- http://127.0.0.1/healthz | grep -q ok || exit 1
