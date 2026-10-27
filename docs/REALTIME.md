# 实时消息（WebSocket）连接说明

前端通过 `GatewayWorker` 接收新回复、新通知。浏览器连的是 `window.GATEWAY_WS_URL?token=...`，
这个地址来自 `.env` 的 `GATEWAY_PUBLIC_WS_URL`，由 `resources/views/app.blade.php` 注入。

## 地址怎么决定

| `.env` 配置 | 实际连接地址 |
|---|---|
| `GATEWAY_PUBLIC_WS_URL=wss://t.test:1238` | 直连网关（网关自己开 TLS） |
| `GATEWAY_PUBLIC_WS_URL=`（留空） | 自动用当前站点：`https://t.test` → `wss://t.test/ws`；`http://t.test` → `ws://t.test/ws` |

留空时需要在站点的 nginx 里把 `/ws` 反代到网关。

## 常见问题

**页面是 `https://`，连接却写成 `ws://192.168.x.x:1238`**

两个问题叠加：

1. HTTPS 页面不允许连 `ws://`（浏览器按混合内容直接拦掉，控制台会报 *Mixed Content*）；
2. 写死局域网 IP 后，换域名/换网络就失效。

前端的兜底会把 `ws://` 自动升级成 `wss://` 并打印警告，但证书必须和访问的域名匹配，
所以正确做法是二选一（下面两种）。

## 方案 A：同源反代（推荐，生产也用这个）

网关只监听本机明文，TLS 由 nginx 终结：

```nginx
location /ws {
    proxy_pass http://127.0.0.1:1238;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

`.env`：

```dotenv
GATEWAY_MODE=proxy
GATEWAY_HOST=127.0.0.1
GATEWAY_SSL=false
GATEWAY_PUBLIC_WS_URL=
GATEWAY_PUBLIC_WS_PATH=/ws
```

重启：`php artisan gateway:start --restart`，然后刷新页面（`wss://你的域名/ws`）。

## 方案 B：网关直接开 wss（不改 nginx）

需要一张和访问域名匹配的证书。本机用 ServBay 的 `t.test` 证书即可：

```dotenv
GATEWAY_MODE=direct
GATEWAY_HOST=0.0.0.0
GATEWAY_PORT=1238
GATEWAY_SSL=true
GATEWAY_SSL_CERT=/Applications/ServBay/ssl/private/tls-certs/t.test/t.test.crt
GATEWAY_SSL_KEY=/Applications/ServBay/ssl/private/tls-certs/t.test/t.test.key
GATEWAY_PUBLIC_WS_URL=wss://t.test:1238
```

重启：`php artisan gateway:start --restart`。浏览器打开 `https://t.test` 时连 `wss://t.test:1238`。

证书续期/换域名后记得同步改路径和地址。

## ServBay 本地环境注意

ServBay 的 vhost 文件（`/Applications/ServBay/etc/nginx/vhosts/*.conf`）是**自动生成**的，
改了之后只要在 ServBay 里保存一次站点配置就会被覆盖，所以不要在 `t.test.conf` 里手工加 `location /ws` 当长期方案。

两个可行做法：

**做法 1：给 WS 单独开一个反向代理站点（推荐，改动最小且持久）**

1. ServBay → 网站 → 新增网站 → 类型选「反向代理」，域名填 `ws.t.test`，目标 `http://127.0.0.1:1238`；
2. `.env` 里 `GATEWAY_MODE=proxy`、`GATEWAY_PUBLIC_WS_URL=wss://ws.t.test`（不带端口）；
3. `php artisan gateway:start --restart`；
4. 浏览器连 `wss://ws.t.test?token=...`，证书由 ServBay 为 `ws.t.test` 签，同一个根证书信任一次即可。

**做法 2：坚持同源 `/ws`**

把方案 A 的 `location /ws` 手工加进 `t.test.conf`，能用；但 ServBay 重新生成配置后会丢，
需要重新粘贴（或把这段存成 snippet 备用）。日志里客户端 IP 会变成 `127.0.0.1`，如需真实 IP
要在 nginx 侧透传并由网关读取 `X-Forwarded-For`（当前网关未实现，鉴权不依赖 IP，一般可忽略）。

## 排查清单

- 网关是否在跑：`php artisan gateway:start --status`，或 `lsof -nP -iTCP:1238 -sTCP:LISTEN`
- 网关日志：`storage/logs/gateway.log`、`storage/logs/gateway_stdout.log`
- 浏览器控制台 Network → WS：看握手返回 101 还是 400/502；`[realtime]` 警告里有实际连接的地址
- 反代没配 `Upgrade`/`Connection` 头时，握手会失败（nginx 返回 400 或直接断开）
- 同一浏览器多标签页会各建一条连接，属正常
- 当前 token 走查询参数（`?token=`），会出现在网关访问日志里；后续可改成握手后首帧鉴权

## 相关文件

- `resources/js/realtime.js`：连接、重连、地址兜底
- `resources/views/app.blade.php`：注入 `window.GATEWAY_WS_URL`
- `config/gateway.php`：`public_ws_url` / `public_ws_path`
- `gateway/start.php`：网关监听地址、TLS
- `gateway/Events.php`：token 鉴权、推送
