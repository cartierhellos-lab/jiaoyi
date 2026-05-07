#!/bin/bash
# ================================================
# 交易所一键部署脚本 (兼容 CentOS 7 + MariaDB 5.5)
# ================================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}[✓] $1${NC}"; }
warn() { echo -e "${YELLOW}[!] $1${NC}"; }
err()  { echo -e "${RED}[✗] $1${NC}"; exit 1; }

echo -e "${GREEN}"
echo "================================================"
echo "  交易所系统 一键部署脚本"
echo "  项目: cartierhellos-lab/jiaoyi"
echo "================================================"
echo -e "${NC}"

# ---------- 1. 检测系统 ----------
log "检测系统环境..."
if [ -f /etc/debian_version ]; then
    PKG="apt-get"
    log "系统: Debian/Ubuntu"
elif [ -f /etc/redhat-release ]; then
    PKG="yum"
    log "系统: CentOS/RHEL"
else
    err "不支持的系统类型"
fi

# ---------- 2. 安装依赖 ----------
log "更新软件源..."
if [ "$PKG" = "apt-get" ]; then
    $PKG update -y -q
fi

log "安装 Nginx..."
$PKG install -y -q nginx

log "安装 PHP 及扩展..."
if [ "$PKG" = "apt-get" ]; then
    $PKG install -y -q php php-fpm php-mysql php-mbstring php-gd php-curl php-zip php-xml php-bcmath
else
    # CentOS 7 使用 EPEL + Remi 源安装 PHP 7.4
    yum install -y epel-release 2>/dev/null || true
    yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm 2>/dev/null || true
    yum-config-manager --enable remi-php74 2>/dev/null || true
    yum install -y php php-fpm php-mysqlnd php-mbstring php-gd php-curl php-zip php-xml php-bcmath php-json 2>/dev/null || \
    yum install -y php php-fpm php-mysql php-mbstring php-gd 2>/dev/null || true
fi

log "安装 MariaDB..."
if [ "$PKG" = "apt-get" ]; then
    $PKG install -y -q mariadb-server
else
    yum install -y mariadb-server mariadb
fi

log "安装 Git / Unzip..."
$PKG install -y git unzip 2>/dev/null || true

# ---------- 3. 启动服务 ----------
log "启动服务..."
systemctl enable mariadb nginx 2>/dev/null || \
  (service mariadb start && service nginx start)
systemctl start mariadb 2>/dev/null || service mariadb start
systemctl start nginx   2>/dev/null || service nginx start

# 启动 PHP-FPM
if systemctl list-units --type=service 2>/dev/null | grep -q php; then
    PHP_FPM_SERVICE=$(systemctl list-units --type=service 2>/dev/null | grep php | grep fpm | awk '{print $1}' | head -1)
    systemctl enable "$PHP_FPM_SERVICE" 2>/dev/null || true
    systemctl start  "$PHP_FPM_SERVICE" 2>/dev/null || service php-fpm start
else
    service php-fpm start 2>/dev/null || true
fi

# ---------- 4. 检测 PHP-FPM socket ----------
log "检测 PHP-FPM socket..."
sleep 2
PHP_FPM_SOCK=$(find /var/run/php/ /run/php/ /var/run/php-fpm/ 2>/dev/null -name "*.sock" | head -1)
if [ -z "$PHP_FPM_SOCK" ]; then
    # CentOS php-fpm 默认用 TCP 127.0.0.1:9000
    PHP_FPM_BACKEND="127.0.0.1:9000"
    warn "使用 TCP 模式: $PHP_FPM_BACKEND"
else
    PHP_FPM_BACKEND="unix:$PHP_FPM_SOCK"
    log "PHP-FPM socket: $PHP_FPM_SOCK"
fi

# ---------- 5. 克隆项目 ----------
log "拉取项目代码..."
mkdir -p /var/www
rm -rf /var/www/jiaoyi
git clone https://github.com/cartierhellos-lab/jiaoyi.git /var/www/jiaoyi || err "克隆失败，请检查仓库是否为Public"

# ---------- 6. 修复 PHP 兼容性 ----------
log "修复 PHP 兼容性..."
sed -i 's/\$input =& \$GLOBALS;/\$input = \$GLOBALS;/' /var/www/jiaoyi/ThinkPHP/Common/functions.php 2>/dev/null || true
sed -i 's/get_magic_quotes_gpc()/false/g' /var/www/jiaoyi/Application/Common/Conf/secure.php 2>/dev/null || true
sed -i 's/\$str = mysql_escape_string(\$str);/\/\/ \$str = mysql_escape_string(\$str);/' /var/www/jiaoyi/Application/Common/Conf/secure.php 2>/dev/null || true

# ---------- 7. 配置数据库（兼容 MariaDB 5.5） ----------
log "配置数据库..."
DB_NAME="jiaoyi"
DB_USER="jiaoyi"
DB_PASS="jiaoyi$(date +%s | sha256sum | base64 | head -c 8)"

# 兼容 MariaDB 5.5 的用户创建方式（不用 IF NOT EXISTS）
mysql -u root 2>/dev/null <<SQL || mysql -u root --skip-password <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
FLUSH PRIVILEGES;
SQL

log "导入 SQL 数据..."
SQL_FILE="/var/www/jiaoyi/jiaoyi.sql"
if [ -f "$SQL_FILE" ]; then
    sed 's/ROW_FORMAT=COMPACT/ROW_FORMAT=DYNAMIC/g' "$SQL_FILE" | mysql -u root $DB_NAME 2>/dev/null || \
    sed 's/ROW_FORMAT=COMPACT/ROW_FORMAT=DYNAMIC/g' "$SQL_FILE" | mysql -u root --skip-password $DB_NAME
    log "SQL 导入完成"
else
    warn "未找到 SQL 文件: $SQL_FILE"
fi

# ---------- 8. 修改数据库配置 ----------
log "配置项目数据库连接..."
sed -i "s/define('DB_NAME', '[^']*');/define('DB_NAME', '$DB_NAME');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_USER', '[^']*');/define('DB_USER', '$DB_USER');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_PWD', '[^']*');/define('DB_PWD', '$DB_PASS');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_HOST', '[^']*');/define('DB_HOST', '127.0.0.1');/" /var/www/jiaoyi/index.php

# ---------- 9. 配置 Nginx ----------
log "配置 Nginx..."
# 清理旧配置
rm -f /etc/nginx/conf.d/default.conf 2>/dev/null || true

cat > /etc/nginx/conf.d/jiaoyi.conf << NGINX
server {
    listen 80 default_server;
    root /var/www/jiaoyi;
    index index.php index.html;
    server_name _;

    location / {
        if (!-e \$request_filename) {
            rewrite ^(.*)\$ /index.php?s=\$1 last;
            break;
        }
    }

    location ~ \.php\$ {
        fastcgi_pass $PHP_FPM_BACKEND;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ ^/(Application|ThinkPHP|Runtime|Database)/ {
        deny all;
    }
}
NGINX

nginx -t 2>&1 || err "Nginx 配置有误"

# ---------- 10. 设置权限 ----------
log "设置目录权限..."
WEB_USER=$(nginx -T 2>/dev/null | grep "user " | awk '{print $2}' | tr -d ';' | head -1)
WEB_USER=${WEB_USER:-nginx}
chown -R $WEB_USER:$WEB_USER /var/www/jiaoyi/ 2>/dev/null || true
chmod -R 755 /var/www/jiaoyi/
chmod -R 777 /var/www/jiaoyi/Runtime/ /var/www/jiaoyi/Upload/ /var/www/jiaoyi/Database/ 2>/dev/null || true

# ---------- 11. 重启服务 ----------
log "重启服务..."
systemctl reload nginx 2>/dev/null || service nginx reload
[ -n "$PHP_FPM_SERVICE" ] && (systemctl restart "$PHP_FPM_SERVICE" 2>/dev/null || service php-fpm restart)

# ---------- 12. 完成 ----------
sleep 2
SERVER_IP=$(curl -s --max-time 3 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ 2>/dev/null)

echo ""
echo -e "${GREEN}================================================"
echo "  🎉 部署完成！"
echo "================================================${NC}"
echo ""
echo -e "  前台地址：${GREEN}http://$SERVER_IP/${NC}"
echo -e "  后台地址：${GREEN}http://$SERVER_IP/index.php/Admin/Login${NC}"
echo ""
echo -e "  后台账号：${YELLOW}admin${NC} / 密码：${YELLOW}123456${NC}"
echo -e "  前台账号：${YELLOW}bit-z8.com${NC} / 密码：${YELLOW}123456${NC}"
echo ""
echo -e "  数据库：${YELLOW}$DB_NAME${NC} | 用户：${YELLOW}$DB_USER${NC} | 密码：${YELLOW}$DB_PASS${NC}"
echo -e "  HTTP状态：${GREEN}$HTTP_CODE${NC}"
echo ""
warn "请及时修改后台默认密码！"
