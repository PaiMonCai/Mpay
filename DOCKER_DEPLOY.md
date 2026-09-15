# MPAY Docker 部署指南

本仓库包含 MPAY、内置支付宝 OpenAPI 账单 watcher、MySQL 8 和 Redis 7 的 Docker 部署配置。

## 1. 准备服务器

需要：

- Docker Engine 24+（较旧版本通常也能运行）
- Docker Compose v2
- 一个已解析到服务器的域名
- 推荐使用 Nginx / OpenResty / 1Panel 反向代理并配置 HTTPS

默认情况下 MPAY 只绑定宿主机 `127.0.0.1:8787`，MySQL 和 Redis 完全不映射宿主机端口。

## 2. 初始化

```bash
cp .env.docker.example .env
```

编辑 `.env`，至少替换：

```env
MYSQL_PASSWORD=强随机密码
MYSQL_ROOT_PASSWORD=另一个强随机密码
REDIS_PASSWORD=强随机密码
```

也可以直接执行：

```bash
chmod +x docker/setup.sh
docker/setup.sh
```

该脚本会在 `.env` 不存在时自动生成随机密码，然后构建并启动容器。

手工启动：

```bash
docker compose up -d --build
```

检查状态：

```bash
docker compose ps
docker compose logs -f app
```

## 3. 反向代理

把你的域名反代到：

```text
http://127.0.0.1:8787
```

Webman/MPAY 自己直接处理 HTTP 请求，不需要在容器内再套 Nginx。

生产环境必须启用 HTTPS。

## 4. 首次安装

访问：

```text
https://你的域名/install
```

Docker Compose 默认参数对应：

| 安装项 | 填写值 |
|---|---|
| 数据库地址 | `mysql` |
| 数据库端口 | `3306` |
| 数据库名 | `.env` 中的 `MYSQL_DATABASE`，默认 `mpay` |
| 数据库用户名 | `.env` 中的 `MYSQL_USER`，默认 `mpay` |
| 数据库密码 | `.env` 中的 `MYSQL_PASSWORD` |
| Redis 地址 | `redis` |
| Redis 端口 | `6379` |
| Redis 密码 | `.env` 中的 `REDIS_PASSWORD` |
| Redis DB | `0` |
| Queue DB | `1` |

MySQL 容器会在第一次启动时预创建数据库及应用用户，所以应用不需要保存 MySQL root 密码。

安装完成后，MPAY 会写入持久化的：

```text
data/config/app.env
data/config/epay-platform-private.pem
data/config/epay-platform-public.pem
data/runtime/install.lock
```

因此重新构建/替换 `app` 容器不会丢失安装状态。

如果安装页面提示需要重启：

```bash
docker compose restart app
```

## 5. 启用内置支付宝账单 watcher

在后台运行时配置中启用：

```text
启用网页流水监听
内置支付宝账单 OpenAPI watcher
```

然后在支付宝账单收款插件中配置你自己的：

- 支付宝开放平台 AppID
- 应用私钥
- 支付宝公钥
- `bill_user_id`
- 收款二维码等插件字段

该实现不会使用或绕过原版外置 watcher 的授权系统；它通过你的支付宝开放平台应用调用账务 API。支付宝应用本身必须拥有对应接口权限。

推荐先使用“金额变动”匹配模式完成小额实单测试。

## 6. 常用命令

```bash
# 状态
docker compose ps

# 应用日志
docker compose logs -f app

# MySQL 日志
docker compose logs -f mysql

# Redis 日志
docker compose logs -f redis

# 重启 MPAY
docker compose restart app

# 重新构建应用
docker compose up -d --build app

# Webman 状态
docker compose exec app php webman status

# 数据库迁移
docker compose exec app php webman migrate

# 系统配置同步
docker compose exec app php webman system:config-sync
```

## 7. 数据目录

不要删除这些目录：

```text
data/config          # MPAY .env + 平台 RSA 密钥
data/runtime         # install.lock、私有文件、日志和运行状态
data/public-storage  # 商户/管理员上传的公开文件
data/mysql           # MySQL 数据
data/redis           # Redis AOF 数据
```

`data/` 已被 Git 忽略，绝对不要把生产数据、私钥和密码上传到 GitHub。

## 8. 备份

```bash
chmod +x docker/backup.sh
docker/backup.sh
```

默认输出到：

```text
backups/mpay-YYYYMMDD-HHMMSS/
```

至少应异地保存数据库 dump、`data/config`、`data/runtime/storage/private` 和 `data/public-storage`。

## 9. 更新

```bash
git pull
docker compose up -d --build app
docker compose exec app php webman migrate
docker compose exec app php webman system:config-sync
docker compose restart app
```

更新前请先备份数据库和 `data/`。

## 10. GitHub 注意事项

不要提交：

- `.env`
- `data/`
- 支付宝应用私钥
- ePay 平台私钥
- 数据库备份
- 生产日志

本项目包中只有已编译的后台/商户/收银台前端，因此 `public/admin`、`public/mer`、`public/cashier` 和对应 `public/assets` 必须提交到仓库，否则 Docker 构建后页面会缺失。

另外，在公开 GitHub 仓库前，请确认原项目、编译前端和所有随包支付插件的许可证允许再分发；如果无法确认，建议使用私有仓库。
