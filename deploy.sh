#!/bin/bash
# ================================================
# 交易所一键部署脚本
# 环境：Debian/Ubuntu/CentOS
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
$PKG update -y -q

log "安装 Nginx..."
$PKG install -y -q nginx

log "安装 PHP 及扩展..."
if [ "$PKG" = "apt-get" ]; then
    $PKG install -y -q php php-fpm php-mysql php-mbstring php-gd php-curl php-zip php-xml php-bcmath
    PHP_FPM_SERVICE=$(systemctl list-units --type=service | grep php | grep fpm | awk '{print $1}' | head -1)
    PHP_FPM_SOCK=$(find /var/run/php/ -name "*.sock" 2>/dev/null | head -1)
else
    yum install -y epel-release
    yum install -y php php-fpm php-mysqlnd php-mbstring php-gd php-curl php-zip php-xml php-bcmath
    PHP_FPM_SERVICE="php-fpm"
    PHP_FPM_SOCK="/var/run/php-fpm/www.sock"
fi

log "安装 MariaDB..."
if [ "$PKG" = "apt-get" ]; then
    $PKG install -y -q mariadb-server
else
    yum install -y mariadb-server mariadb
fi

log "安装 Git / Unzip..."
$PKG install -y -q git unzip

# ---------- 3. 启动服务 ----------
log "启动服务..."
systemctl enable mariadb nginx 2>/dev/null || true
systemctl start mariadb nginx

if [ -n "$PHP_FPM_SERVICE" ]; then
    systemctl enable $PHP_FPM_SERVICE 2>/dev/null || true
    systemctl start $PHP_FPM_SERVICE
fi

# ---------- 4. 自动检测 PHP-FPM socket ----------
log "检测 PHP-FPM socket..."
sleep 2
PHP_FPM_SOCK=$(find /var/run/php/ /run/php/ 2>/dev/null -name "*.sock" | head -1)
if [ -z "$PHP_FPM_SOCK" ]; then
    PHP_FPM_SOCK="/run/php/php-fpm.sock"
    warn "未找到 socket，使用默认路径: $PHP_FPM_SOCK"
fi
log "PHP-FPM socket: $PHP_FPM_SOCK"

# ---------- 5. 克隆项目 ----------
log "拉取项目代码..."
mkdir -p /var/www
rm -rf /var/www/jiaoyi

# 克隆项目（使用 HTTPS 无需 SSH key）
git clone https://github.com/cartierhellos-lab/jiaoyi.git /var/www/jiaoyi || err "克隆失败，请检查网络或仓库是否为Public"

# ---------- 6. 修复 PHP8 兼容性 ----------
log "修复 PHP8 兼容性..."
# 修复 $GLOBALS 引用
sed -i 's/\$input =& \$GLOBALS;/\$input = \$GLOBALS;/' /var/www/jiaoyi/ThinkPHP/Common/functions.php 2>/dev/null || true
# 修复 get_magic_quotes_gpc
sed -i 's/get_magic_quotes_gpc()/false/g' /var/www/jiaoyi/Application/Common/Conf/secure.php 2>/dev/null || true
# 注释 mysql_escape_string
sed -i 's/\$str = mysql_escape_string(\$str);/\/\/ \$str = mysql_escape_string(\$str);/' /var/www/jiaoyi/Application/Common/Conf/secure.php 2>/dev/null || true

# ---------- 7. 配置数据库 ----------
log "配置数据库..."
DB_NAME="jiaoyi"
DB_USER="jiaoyi"
DB_PASS="jiaoyi$(date +%s | sha256sum | base64 | head -c 8)"

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

log "设置 InnoDB 行格式..."
mysql -u root -e "SET GLOBAL innodb_default_row_format='DYNAMIC';" 2>/dev/null || true

log "导入 SQL 数据..."
# 下载 SQL（从仓库获取或者用内嵌的最小结构）
SQL_FILE=$(find /var/www/jiaoyi -name "*.sql" 2>/dev/null | head -1)
if [ -n "$SQL_FILE" ]; then
    sed 's/ROW_FORMAT=COMPACT/ROW_FORMAT=DYNAMIC/g' "$SQL_FILE" | mysql -u root $DB_NAME
    log "SQL 导入完成"
else
    warn "未找到 SQL 文件，请手动导入数据库"
fi

# ---------- 8. 修改项目数据库配置 ----------
log "配置项目数据库连接..."
sed -i "s/define('DB_NAME', '[^']*');/define('DB_NAME', '$DB_NAME');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_USER', '[^']*');/define('DB_USER', '$DB_USER');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_PWD', '[^']*');/define('DB_PWD', '$DB_PASS');/" /var/www/jiaoyi/index.php
sed -i "s/define('DB_HOST', '[^']*');/define('DB_HOST', '127.0.0.1');/" /var/www/jiaoyi/index.php

# ---------- 9. 配置 Nginx ----------
log "配置 Nginx..."
cat > /etc/nginx/sites-available/jiaoyi << NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;

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
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ ^/(Application|ThinkPHP|Runtime|Database)/ {
        deny all;
    }
}
NGINX

# 启用站点
mkdir -p /etc/nginx/sites-enabled
ln -sf /etc/nginx/sites-available/jiaoyi /etc/nginx/sites-enabled/jiaoyi
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# CentOS 需要额外配置
if [ "$PKG" = "yum" ]; then
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
        fastcgi_pass unix:/var/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ ^/(Application|ThinkPHP|Runtime|Database)/ { deny all; }
}
NGINX
fi

nginx -t || err "Nginx 配置有误"

# ---------- 10. 设置权限 ----------
log "设置目录权限..."
chown -R www-data:www-data /var/www/jiaoyi/ 2>/dev/null || \
chown -R nginx:nginx /var/www/jiaoyi/ 2>/dev/null || true
chmod -R 755 /var/www/jiaoyi/
chmod -R 777 /var/www/jiaoyi/Runtime/ /var/www/jiaoyi/Upload/ /var/www/jiaoyi/Database/ 2>/dev/null || true

# ---------- 11. 重启服务 ----------
log "重启服务..."
systemctl reload nginx
[ -n "$PHP_FPM_SERVICE" ] && systemctl restart $PHP_FPM_SERVICE

# ---------- 12. 验证 ----------
log "验证部署..."
sleep 2
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/ 2>/dev/null)

# ---------- 完成 ----------
SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
echo ""
echo -e "${GREEN}================================================"
echo "  🎉 部署完成！"
echo "================================================${NC}"
echo ""
echo -e "  前台地址：${GREEN}http://$SERVER_IP/${NC}"
echo -e "  后台地址：${GREEN}http://$SERVER_IP/index.php/Admin/Login${NC}"
echo ""
echo -e "  后台账号：${YELLOW}admin${NC}"
echo -e "  后台密码：${YELLOW}123456${NC}"
echo ""
echo -e "  数据库名：${YELLOW}$DB_NAME${NC}"
echo -e "  数据库账号：${YELLOW}$DB_USER${NC}"
echo -e "  数据库密码：${YELLOW}$DB_PASS${NC}"
echo ""
echo -e "  HTTP状态：${GREEN}$HTTP_CODE${NC}"
echo ""
warn "请及时修改后台默认密码！"
echo ""
