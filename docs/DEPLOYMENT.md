# 部署与运维手册

面向单机部署（nginx + PHP-FPM + MySQL + 队列 + GatewayWorker）。所有命令以 `/var/www/ticket` 为项目目录，按实际路径替换。

## 1. 依赖

- PHP 8.3（扩展：mbstring、pdo_mysql、intl、gd、zip、redis 可选）
- MySQL 8.0+（`utf8mb4`）
- Node 20（仅构建前端，产物在 `public/build`）
- nginx、supervisor、cron

## 2. 首次部署

```bash
cd /var/www/ticket
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env && php artisan key:generate

# .env 必改：APP_ENV=production、APP_DEBUG=false、APP_URL、DB_*、MAIL_*、SANCTUM_EXPIRATION
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache

npm ci && npm run build
chown -R www-data:www-data storage bootstrap/cache
```

首次部署后到「系统设置」确认站点名、SLA、附件上限；账号来源只有管理员创建 / 手机号自动建号，没有自助注册。

## 3. 常驻进程（supervisor）

```bash
cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
supervisorctl reread && supervisorctl update
supervisorctl status
```

- `ticket-worker`：队列（邮件、通知）。没它邮件不会发。
- `ticket-gateway`：GatewayWorker（实时消息）。没它页面只是不实时，功能不受影响。

## 4. 定时任务（cron）

```cron
* * * * * www-data cd /var/www/ticket && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` 里目前有：SLA 超时检查、`pending_resolved` 自动关闭（02:00）、每日备份（03:10）、附件清理（每周）。

## 5. 备份与恢复

```bash
php artisan system:backup --keep-days=14     # 数据库 + storage/app/public 附件 → storage/backups
```

- 备份文件：`db-*.sql.gz` 与 `storage-public-*.tar.gz`，默认保留 14 天。
- **必须把 `storage/backups` 同步到异地**（对象存储/另一台机器），否则磁盘挂了备份也没了。
- 恢复数据库：`gunzip -c db-xxx.sql.gz | mysql -u root -p ticket`
- 恢复附件：`tar -xzf storage-public-xxx.tar.gz -C storage/app/public`
- 恢复后执行 `php artisan optimize:clear`，并确认 `storage/app/public` 权限属于 www-data。

**至少演练一次恢复**，没演练过的备份等于没有备份。

## 6. 健康检查

- `GET /up`：Laravel 内置存活检查（极轻量）。
- `GET /api/health`：数据库、缓存、存储可写、队列积压；返回 200/503，可直接给负载均衡或拨测用。

## 7. 日志

- 默认 `LOG_CHANNEL=daily`，保留 14 天（`LOG_DAILY_DAYS`），不再无限增长。
- `storage/logs/laravel.log`、`worker.log`、`gateway.log` 建议接 logrotate 或采集（Loki/ELK 均可）。
- 队列失败任务：`php artisan queue:failed` 查看，`queue:retry {id}` 重试。

## 8. nginx 要点

```nginx
root /var/www/ticket/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { fastcgi_pass unix:/run/php/php8.3-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
# 可选：/ws 反代到网关，前端 GATEWAY_PUBLIC_WS_URL 留空即可
location /ws {
    proxy_pass http://127.0.0.1:1238;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

反向代理场景在 `.env` 配 `TRUSTED_PROXIES=127.0.0.1`（Cloudflare 等填 `*`），否则 `https` 判断与限流 IP 会失真。

## 9. 上线安全清单

- [ ] `APP_ENV=production`、`APP_DEBUG=false`
- [ ] 全站 HTTPS，`APP_URL` 用 https
- [ ] `TRUSTED_PROXIES` 已配
- [ ] `SANCTUM_EXPIRATION` 小于一年（默认 30 天）
- [ ] 管理员密码非默认值，删除演示账号
- [ ] `storage/backups` 已异地同步，且做过一次恢复演练
- [ ] `/api/health` 已接入监控
- [ ] supervisor 三个进程均 `RUNNING`

## 10. 升级流程

```bash
git pull
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
supervisorctl restart ticket-worker:* ticket-gateway
```

改了 `gateway/Events.php` 必须重启 `ticket-gateway`，否则鉴权逻辑仍是旧的。
