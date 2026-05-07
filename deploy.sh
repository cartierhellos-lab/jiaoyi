#!/bin/bash
# ================================================
# 交易所主服务器部署脚本
# 数据库：内网 10.4.0.13 (Ubuntu数据库服务器)
# ================================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}[✓] $1${NC}"; }
warn() { echo -e "${YELLOW}[!] $1${NC}"; }
err()  { echo -e "${RED}[✗] $1${NC}"; exit 1; }

echo -e "${GREEN}"
echo "================================================"
echo "  交易所主服务器 部署脚本"
echo "  数据库: 10.4.0.13 (内网)"
echo "================================================"
echo -e "${NC}"

DB_HOST="10.4.0.13"
DB_NAME="jiaoyi"
DB_USER="jiaoyi"
DB_PASS="Jiaoyi@2026#Hkd"

# ---------- 1. 安装依赖 ----------
log "安装基础依赖..."
yum install -y epel-release 2>/dev/null || true
yum install -y nginx git unzip 2>/dev/null || \
apt-get install -y nginx git unzip 2>/dev/null || true

# ---------- 2. 安装 PHP ----------
log "安装 PHP..."
if command -v yum &>/dev/null; then
    yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm 2>/dev/null || true
    yum-config-manager --enable remi-php74 2>/dev/null || true
    yum install -y php php-fpm php-mysqlnd php-mbstring php-gd php-curl php-xml php-bcmath php-json 2>/dev/null || \
    yum install -y php php-fpm php-mysql php-mbstring php-gd 2>/dev/null || true
else
    apt-get install -y php php-fpm php-mysql php-mbstring php-gd php-curl php-zip php-xml php-bcmath 2>/dev/null || true
fi

# ---------- 3. 启动服务 ----------
log "启动服务..."
systemctl start nginx 2>/dev/null || service nginx start
systemctl enable nginx 2>/dev/null || true

# 启动 PHP-FPM
if systemctl list-units --type=service 2>/dev/null | grep -q "php.*fpm"; then
    PHP_SVC=$(systemctl list-units --type=service 2>/dev/null | grep "php.*fpm" | awk '{print $1}' | head -1)
    systemctl start $PHP_SVC 2>/dev/null || true
    systemctl enable $PHP_SVC 2>/dev/null || true
else
    service php-fpm start 2>/dev/null || true
fi

# ---------- 4. 检测 PHP-FPM ----------
log "检测 PHP-FPM..."
sleep 2
PHP_SOCK=$(find /var/run/php/ /run/php/ /var/run/php-fpm/ 2>/dev/null -name "*.sock" | head -1)
if [ -n "$PHP_SOCK" ]; then
    PHP_BACKEND="unix:$PHP_SOCK"
    log "Socket: $PHP_SOCK"
else
    PHP_BACKEND="127.0.0.1:9000"
    warn "使用 TCP 模式: $PHP_BACKEND"
fi

# ---------- 5. 拉取代码 ----------
log "拉取项目代码..."
rm -rf /var/www/jiaoyi
git clone https://github.com/cartierhellos-lab/jiaoyi.git /var/www/jiaoyi || err "克隆失败，请确认仓库为Public"

# ---------- 6. 修复 PHP 兼容性 ----------
log "修复 PHP 兼容性..."
sed -i 's/\$input =& \$GLOBALS;/\$input = \$GLOBALS;/' /var/www/jiaoyi/ThinkPHP/Common/functions.php 2>/dev/null || true
sed -i 's/get_magic_quotes_gpc()/false/g' /var/www/jiaoyi/Application/Common/Conf/secure.php 2>/dev/null || true
sed -i 's/\$str = mysql_escape_string(\$str);/\/\/ \$str = mysql_escape_string(\$str);/' /var/www/jiaoyi/Application/Common/Conf/secure.php 2>/dev/null || true

# ---------- 7. 配置数据库连接 ----------
log "配置数据库连接 -> $DB_HOST..."
sed -i "s/define('DB_HOST', '[^']*');/define('DB_HOST', '$DB_HOST');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_NAME', '[^']*');/define('DB_NAME', '$DB_NAME');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_USER', '[^']*');/define('DB_USER', '$DB_USER');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_PWD', '[^']*');/define('DB_PWD', '$DB_PASS');/" /var/www/jiaoyi/index.php

# ---------- 8. 配置 Nginx ----------
log "配置 Nginx..."
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
        fastcgi_pass $PHP_BACKEND;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ ^/(Application|ThinkPHP|Runtime|Database)/ { deny all; }
}
NGINX

nginx -t || err "Nginx 配置有误"

# ---------- 9. 目录权限 ----------
log "设置权限..."
WEB_USER=$(nginx -T 2>/dev/null | grep "^user " | awk '{print $2}' | tr -d ';' | head -1)
WEB_USER=${WEB_USER:-nginx}
chown -R $WEB_USER:$WEB_USER /var/www/jiaoyi/ 2>/dev/null || true
chmod -R 755 /var/www/jiaoyi/
chmod -R 777 /var/www/jiaoyi/Runtime/ /var/www/jiaoyi/Upload/ /var/www/jiaoyi/Database/ 2>/dev/null || true

# ---------- 10. 重启 ----------
log "重启服务..."
systemctl reload nginx 2>/dev/null || service nginx reload
[ -n "$PHP_SVC" ] && systemctl restart $PHP_SVC 2>/dev/null || service php-fpm restart 2>/dev/null || true

# ---------- 完成 ----------
sleep 2
SERVER_IP=$(curl -s --max-time 3 ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ 2>/dev/null)

echo ""
echo -e "${GREEN}================================================"
echo "  🎉 部署完成！"
echo "================================================${NC}"
echo ""
echo -e "  前台：${GREEN}http://hkdex.net/${NC}"
echo -e "  后台：${GREEN}http://hkdex.net/index.php/Admin/Login${NC}"
echo ""
echo -e "  后台账号：${YELLOW}admin${NC} / 密码：${YELLOW}123456${NC}"
echo ""
echo -e "  数据库：${YELLOW}$DB_HOST${NC}（内网连接）"
echo -e "  HTTP状态：${GREEN}$HTTP_CODE${NC}"
echo ""
warn "请及时修改后台默认密码！"
