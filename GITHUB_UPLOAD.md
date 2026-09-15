# 上传到 GitHub

建议使用 **Private（私有）仓库**，除非你已经确认原 MPAY 项目、编译后的前端资产以及随包支付插件允许公开再分发。

## 新建仓库后上传

解压 `mpay-docker-github-ready.zip` 后，在目录内执行：

```bash
git init
git branch -M main
git add .
git commit -m "Initial MPAY Docker release"
git remote add origin https://github.com/你的用户名/你的仓库.git
git push -u origin main
```

仓库中不要提交生产环境的：

```text
.env
data/
backups/
epay-platform-private.pem
支付宝应用私钥
数据库备份
```

这些已经通过 `.gitignore` 排除。

## 在服务器上部署

```bash
git clone https://github.com/你的用户名/你的仓库.git
cd 你的仓库
chmod +x docker/setup.sh docker/backup.sh
./docker/setup.sh
```

然后使用 1Panel / OpenResty / Nginx 把域名反向代理至：

```text
http://127.0.0.1:8787
```

访问：

```text
https://你的域名/install
```

Docker 内部数据库/Redis Host 分别填写：

```text
mysql
redis
```

完整说明见 `DOCKER_DEPLOY.md`。
