#!/bin/bash
set -e

CONF="/etc/nginx/sites-enabled/dev.crmby.by"

if [ ! -f "$CONF" ]; then
    echo "ERROR: $CONF not found"
    exit 1
fi

if grep -q "Tris-Servis2026" "$CONF"; then
    echo "Block /Tris-Servis2026/ already exists in nginx config — skip"
    exit 0
fi

cp "$CONF" "${CONF}.bak"
echo "Backup: ${CONF}.bak"

head -n -1 "$CONF" > /tmp/nginx_patch.conf

cat >> /tmp/nginx_patch.conf << 'NGINX_EOF'
    location /Tris-Servis2026/ {
        alias /var/www/Tris-Servis2026/;
        index index.php;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass php_dev_crmby;
            fastcgi_param SCRIPT_FILENAME $request_filename;
        }

        location ~ /\.(ht|git|env) {
            deny all;
        }
    }
}
NGINX_EOF

cp /tmp/nginx_patch.conf "$CONF"
nginx -t && systemctl reload nginx
echo ""
echo "Done! App: https://dev.crmby.by/Tris-Servis2026/index.php"
