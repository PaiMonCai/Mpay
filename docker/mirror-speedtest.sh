#!/usr/bin/env bash
# 实测候选 Docker Hub 镜像加速器的拉取速度，把最快的写入 /etc/docker/daemon.json。
# 用法: sudo bash docker/mirror-speedtest.sh
set -euo pipefail

MIRRORS=(
    "https://docker.m.daocloud.io"
    "https://docker.1ms.run"
    "https://docker.xuanyuan.me"
)
TEST_IMAGE="library/hello-world:latest"

results=()
for mirror in "${MIRRORS[@]}"; do
    host="${mirror#https://}"
    image="${host}/${TEST_IMAGE}"
    docker rmi "$image" >/dev/null 2>&1 || true

    start=$(date +%s%N)
    if docker pull "$image" >/dev/null 2>&1; then
        end=$(date +%s%N)
        ms=$(( (end - start) / 1000000 ))
        echo "OK   ${mirror}  ${ms}ms"
        results+=("${ms} ${mirror}")
    else
        echo "FAIL ${mirror}  不可用"
    fi
    docker rmi "$image" >/dev/null 2>&1 || true
done

if [ ${#results[@]} -eq 0 ]; then
    echo "所有候选镜像站均不可用，请检查网络或更新候选列表。" >&2
    exit 1
fi

best=$(printf '%s\n' "${results[@]}" | sort -n | head -1 | cut -d' ' -f2-)
echo "最快镜像站: ${best}"

if [ -f /etc/docker/daemon.json ] && grep -q 'registry-mirrors' /etc/docker/daemon.json; then
    echo "/etc/docker/daemon.json 已配置 registry-mirrors，请手工把 ${best} 放到列表第一位。"
    exit 0
fi

if [ "$(id -u)" != "0" ]; then
    echo "需要 root 权限写入 /etc/docker/daemon.json，请用 sudo 重新运行。" >&2
    exit 1
fi

mkdir -p /etc/docker
cat > /etc/docker/daemon.json <<EOF
{
  "registry-mirrors": ["${best}"]
}
EOF
systemctl restart docker
echo "已写入 /etc/docker/daemon.json 并重启 Docker。"
