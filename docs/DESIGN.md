# 工单系统设计文档（Ticket System）

> 项目路径：`/Users/a123/Desktop/code/ticket`
> 版本：v2.8（2026-10-25；v2.2 队列化/toast 修复；v2.1 网关模式开关，v2.0 网关命令行/回复即时消息，v1.9 网关推送，v1.8 提及补全，v1.7 概览卡片，v1.6 账号/UI 翻新，v1.5 测试套件，v1.4 暗黑模式，v1.3 CSV/MySQL，v1.2 SLA/知识库，v1.1 通知/附件/评价，v1.0 主体）

## 1. 项目概述

一个前后端分离的工单（Helpdesk / Ticketing）系统：

- **后端 API**：Laravel 13（最新版，v13.x）+ PHP 8.3
- **前端页面**：Vue 3 + Vite（Laravel 内置 Vite 集成）+ Element Plus
- **数据库**：MySQL 8.0（本机 ServBay，库 `ticket`，utf8mb4；2026-10-25 起）；可随时切回 SQLite（改 `.env` 的 `DB_CONNECTION=sqlite`）
- **认证**：Laravel Sanctum（SPA Token 认证）

核心能力：用户提交工单、客服/管理员处理回复、分类/优先级/状态流转、指派、统计看板。

## 2. 角色与权限

| 角色 | role 值 | 能力 |
|------|---------|------|
| 普通用户 | `user` | 创建工单、查看/回复自己的工单 |
| 客服 | `agent` | 查看所有工单、回复、认领/被指派、更新状态 |
| 管理员 | `admin` | 客服能力 + 用户管理、分类管理、统计看板 |

登录后 JWT 风格的 Sanctum Token（Bearer），前端存 localStorage，axios 拦截器统一携带。

## 3. 数据库设计

### users
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| name | string | 姓名 |
| email | string unique | 登录邮箱 |
| password | string | bcrypt |
| role | enum: user/agent/admin | 默认 user |
| created_at / updated_at | timestamps | |

### categories 分类
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| name | string unique | 分类名，如 网络/账号/账单 |
| description | string nullable | 描述 |

### tickets 工单
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| no | string unique | 工单号，如 TK-20261024-0001 |
| title | string | 标题 |
| content | text | 详情 |
| status | enum: open/in_progress/pending_resolved/closed | 状态：待处理/处理中/待确认/已关闭 |
| priority | enum: low/medium/high/urgent | 优先级 |
| user_id | FK users | 提交人 |
| agent_id | FK users nullable | 受理客服 |
| category_id | FK categories nullable | 分类 |
| resolved_at / closed_at | timestamp nullable | 解决/关闭时间 |
| created_at / updated_at | timestamps | |

### ticket_replies 回复
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| ticket_id | FK tickets (cascade) | 所属工单 |
| user_id | FK users | 回复人 |
| content | text | 内容 |
| created_at / updated_at | timestamps | |

### attachments 附件（v1.1）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| ticket_id | FK tickets (cascade) | 所属工单 |
| user_id | FK users | 上传人 |
| original_name | string | 原始文件名 |
| path | string | 存储相对路径（storage/app，附件按工单分目录） |
| size | bigint | 字节数（≤10MB） |
| mime | string(100) | MIME 类型 |

### ticket_logs 操作日志（v1.1）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| ticket_id | FK tickets (cascade) | 所属工单 |
| user_id | FK users | 操作人 |
| action | string(30) | created/replied/status_changed/assigned/priority_changed/category_changed/rated/attachment_uploaded |
| detail | string nullable | 变更说明（旧值 → 新值） |

### notifications（Laravel 标准数据库通知表，v1.1 站内信）
复用 Laravel Notification 系统（database + mail 双通道，邮件驱动为 log 演示）。

### tickets 追加字段（v1.1）
- `rating` tinyint nullable（1-5 满意度）、`rating_comment` string nullable

### tickets 追加字段（v1.2 SLA）
- `sla_due_at`：创建时按优先级计算响应截止（紧急 4h / 高 8h / 中 24h / 低 72h）
- `first_response_at`：客服首次回复时间

### faqs 知识库（v1.2）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint PK | |
| question | string(200) | 问题 |
| answer | text | 答案 |
| sort | int | 排序 |

### 关系
- User hasMany Ticket（提交）/ hasMany Ticket（受理, agent_id）
- Ticket belongsTo User / Agent / Category；hasMany Reply
- Reply belongsTo Ticket / User

## 4. API 设计（REST，前缀 `/api`）

### 认证
| 方法 | 路由 | 说明 |
|------|------|------|
| POST | /api/register | 注册（默认 user 角色） |
| POST | /api/login | 登录，返回 token + user |
| POST | /api/logout | 注销当前 token（认证） |
| GET | /api/me | 当前用户信息 |

### 工单
| 方法 | 路由 | 权限 |
|------|------|------|
| GET | /api/tickets | 列表（user 只见自己的；agent/admin 见全部），支持 ?status=&priority=&category_id=&keyword=&mine= 筛选、分页 |
| POST | /api/tickets | 登录用户创建工单 |
| GET | /api/tickets/{id} | 详情（含回复、关联人）；user 仅限自己的 |
| PUT | /api/tickets/{id} | 更新（agent/admin：状态/优先级/分类/指派；user：仅自己的 open 工单可改标题内容） |
| DELETE | /api/tickets/{id} | admin 删除 |

### 回复
| 方法 | 路由 | 权限 |
|------|------|------|
| GET | /api/tickets/{id}/replies | 随详情返回，也可单独取 |
| POST | /api/tickets/{id}/replies | 提交人本人或 agent/admin 回复 |

### 分类（admin 管理）
| 方法 | 路由 | 权限 |
|------|------|------|
| GET | /api/categories | 所有登录用户 |
| POST / PUT / DELETE | /api/categories{/{id}} | admin |

### 统计（agent/admin）
| 方法 | 路由 | 说明 |
|------|------|------|
| GET | /api/stats | 状态分布、优先级分布、今日新增、未处理数、近 7 天趋势 |

### v1.1 新增
| 方法 | 路由 | 权限 |
|------|------|------|
| POST | /api/tickets/{id}/rating | 提交人评价已关闭工单（1-5 星 + 评语，仅一次） |
| POST | /api/tickets/{id}/attachments | 工单参与者上传附件（multipart，≤10MB） |
| GET | /api/tickets/{id}/attachments/{aid}/download | 参与者下载附件（Storage::download 流式） |
| GET | /api/notifications | 最近 20 条通知 + 未读数 |
| POST | /api/notifications/read | 标记已读（单条或全部） |
| GET | /api/users | admin 用户列表（含提交/受理工单数） |
| PUT | /api/users/{id} | admin 修改用户角色（不能降级自己） |

### v1.2 新增
| 方法 | 路由 | 权限 |
|------|------|------|
| GET | /api/faqs | 登录用户查看知识库 |
| POST / PUT / DELETE | /api/faqs{/{id}} | admin 维护 FAQ |
| GET | /api/tickets?overdue=1 | 仅超时未响应工单 |
| GET | /api/stats | 新增 `overdue`（SLA 超时未响应数） |
| — | `php artisan tickets:check-sla` | 每 5 分钟调度（routes/console.php）：超时未响应工单通知客服 + 写 sla_breach 日志，每单仅提醒一次 |

### SLA 规则（v1.2）
- 创建工单时按优先级写入 `sla_due_at`。
- 客服首次回复记录 `first_response_at`；超时且未响应的工单在列表标红色"超时"，调度任务通知受理客服（未指派则全体客服）。
- 回复内容支持 `@用户名` 提及，被提及者收到 "mentioned" 站内通知。

### v1.3 新增
| 方法 | 路由 | 权限 |
|------|------|------|
| GET | /api/tickets/export | 客服/管理员导出工单 CSV（支持列表全部筛选参数，流式输出，UTF-8 BOM 兼容 Excel 打开中文） |

- 前端工单列表新增"导出 CSV"按钮（仅客服/管理员可见），按当前筛选条件导出，列：工单号/标题/状态/优先级/分类/提交人/受理客服/回复数/满意度评分/SLA截止/首次响应/创建时间/是否超时。

## 13. 自动化测试（v1.5）

- 运行：`php artisan test`（40 个用例 / 148 断言，全绿；约 0.5s）
- 环境：`phpunit.xml` 使用 SQLite `:memory:` + array mailer，与开发/演示库（MySQL `ticket`）完全隔离
- 结构：`tests/Feature/Api/`，基类 `ApiTestCase`（`makeUser(role)` 工厂建号 + `actingAsApi(user)` 走真实 Sanctum token 认证）

| 测试文件 | 覆盖点 |
|------|------|
| AuthTest | 注册默认角色、重复邮箱/弱密码 422、登录 200/401、未认证 401、me/logout、注销后 token 失效 |
| TicketTest | 创建（SLA 按优先级、TK- 工单号、created 日志）、校验 422、user/agent 列表可见范围、跨用户 403、客服更新（状态时间戳+日志）、普通用户仅能改自己 open 工单、仅 admin 可删、筛选与分页、评价规则 |
| ReplyTest | 客服回复自动 open→in_progress + first_response_at、回复通知对方（Notification::fake 断言）、@提及通知、未指派时通知全体客服、非参与者 403、内容必填 |
| AttachmentTest | 上传/下载（Storage::fake）、日志留痕、非参与者 403、超 10MB 422 |
| AdminTest | 分类/FAQ 仅 admin 增删改、角色变更（不能降级自己、agent 403）、用户列表权限、stats 权限与结构、通知列表与已读 |
| SlaTest | overdue 筛选与 isOverdue、closed 永不超时、check-sla 命令通知+日志且每单仅一次、已解决工单不标记 |
| ExportTest | CSV 导出（BOM、表头、内容）、按筛选导出、普通用户 403 |

### 前端冒烟（v2.12，Playwright）
- 运行：`npm run e2e`（14 用例，移动+桌面双视口，~10s；复用运行中的本地服务与演示库，用例幂等）
- 覆盖：客户端登录分流/底部 Tab、staff 客户端登录拒绝、移动端建单→详情、详情回复、user 访问后台弹回、后台登录+列表+回复、后台登录页拒绝普通用户
- 踩坑：登录页双 tab 有两个"密码"占位（strict locator 需 exact+first）；建单需等 /api/categories 返回（分类默认选中竞态）；回复断言用内容可见而非气泡计数（实时推送会并发追加导致计数抖动）

### 已知坑（写给后来者）
- Sanctum 的 `RequestGuard` 会缓存已解析用户，同一测试进程内换 Bearer token 不会重新认证。`ApiTestCase::actingAsApi` 通过 `auth()->forgetGuards()` 丢弃缓存守卫解决；测试令牌注销同理用 `refreshAuth()`。
- 测试工厂的工单号不要用 `Ticket::nextNo()`（批量创建时基于库内最大序号会撞号），工厂里用 `TK-TEST-<uuid>`。

## 18. 即时消息：GatewayWorker 实时推送（v1.9 / v2.0）

### 命令行封装（v2.0）
```bash
php artisan gateway:start -d                # 守护启动（ws，后台分离，约 1s 返回）
php artisan gateway:start --stop            # 停止
php artisan gateway:start --restart -d      # 重启
php artisan gateway:start --status          # 运行状态（进程/连接/崩溃计数）
php artisan gateway:start -d --ssl --cert=fullchain.pem --key=key.pem   # wss 启动
# 等价 .env：GATEWAY_SSL=true GATEWAY_SSL_CERT=… GATEWAY_SSL_KEY=… GATEWAY_PORT=1238
```
- 实现为 `App\Console\Commands\GatewayStartCommand`：分离子进程执行（nohup + stdin 重定向），固定 pid/log 于 storage（`storage/app/gateway.pid`、`storage/logs/gateway.log`）
- wss：`Gateway->transport = 'ssl'` + ssl context（验证：TLS 1.3 握手 + 无效 token 收到 auth_failed 正确断开）
- **坑**：监听协议必须写 `websocket://`（GatewayWorker 约定，服务端握手在 `Workerman\Protocols\Websocket`）；写成 `ws://` 会误用纯客户端协议类 `Ws`——不仅握手被 -1 拒绝，其 `onClose(AsyncTcpConnection)` 类型缺陷还会让 Gateway 子进程在连接断开时崩溃循环（Workerman 5.2.3 已知问题）

### 回复即即时消息（v2.0）
- 回复事件除 notification 外，另推 `type=reply` 载荷（含 id/content/user/时间）给工单参与者（提交人+受理客服，排除发送者）
- 工单详情页收到后**聊天区实时追加气泡**（按 reply.id 去重）+ “有新回复”提示 + 自动滚动，无需刷新
- 铃铛通知与聊天气泡互不重复：正在查看该工单时不弹 toast（由聊天区呈现），未读角标与列表实时更新（浏览器实测：三次事件角标 1→2→3、通知列表实时插入）

### 架构（v1.9）
```
浏览器 ──ws://127.0.0.1:1238?token=<sanctum token>──▶ Gateway（2 进程）
                                                        │ bindUid('u{id}')
Laravel 通知（database+mail+Gateway 通道）─GatewayClient──▶ Register(1236) ─▶ BusinessWorker（鉴权：直查 MySQL personal_access_tokens）
```

- `gateway/start.php`：单机演示版启动脚本（Gateway ws:1238 / Register text:1236 / BusinessWorker×2），启动 `php gateway/start.php start -d`，停止 `stop`，状态 `status`
- `gateway/Events.php`：`onWebSocketConnect` 校验 Sanctum token（SHA-256 比对 `personal_access_tokens`，与 Laravel 逻辑一致），通过后 `bindUid('u{uid}')`；15s 心跳（ping/pong）
- `App\Notifications\Channels\GatewayChannel`：自定义通知通道，`TicketEventNotification` 的 via 增加 `GatewayChannel::class`；网关未启动时 `report()` 静默降级，离线消息不受影响
- 前端 `resources/js/realtime.js`：WebSocket 接入 + 5s 断线重连；铃铛收到推送实时 +1 未读数并弹 ElNotification toast（点击跳工单）；30s 轮询保留作为兜底
- 配置：`.env` 的 `GATEWAY_REGISTER_ADDRESS` / `GATEWAY_PUBLIC_WS_URL` / `GATEWAY_HOST` / `GATEWAY_SSL*`；**测试环境**（phpunit.xml）把 register 地址指到不可达端口，避免测试推送泄漏到真实连接

### 后台登录防护（v2.13：滑块行为验证 + 登录限流）
- 滑块验证（自建，零外部依赖）：`GET /api/captcha/issue` 签发 5 分钟 key → 前端拖到底 `POST /api/captcha/verify` 核销换一次性 ticket → `POST /api/admin/login`（后台专用端点）核销 ticket 放行；key/ticket 均一次性，防重放
- 客户端 `/api/login` 不需要滑块，但与后台登录共用**限流**：每 账号+IP 每分钟 `LOGIN_RATE_LIMIT`（默认 10，可 .env 调）次，超出 429
- 后台登录仍保留"仅 staff"校验（403 拒绝普通用户）
- e2e 适配：Playwright mouse 模拟拖动（issue 下发等待 + done 状态等待）

### 通知队列化（v2.2）
- `TicketEventNotification implements ShouldQueue`：站内信 + 邮件走 database 队列（`QUEUE_CONNECTION=database`），由 `php artisan queue:work` 消费——接真实 SMTP 后邮件不再阻塞回复请求（实测回复接口 ~410ms）
- Gateway 实时通道通过 `viaConnections()` 指定 **sync 连接**：仍在当前请求内同步推送，保证秒达（实测延迟 ~409ms，即接口返回即送达）
- 生产需常驻 `php artisan queue:work`（supervisor 托管）

### 已修复（v2.2）
- NotificationBell 两处缺陷：去重判断用了未声明的 `route`（v2.0 加去重时漏 `useRoute()`，ReferenceError 导致右上角 toast 从未弹出）与 `goTicket` 缺 `http` 导入——均已修复，toast 实测弹出

### 部署形态：直连 vs 反向代理（两种都支持）

**模式总开关**（v2.1）：`GATEWAY_MODE=direct|proxy`（或启动时 `--mode=proxy`），一条命令切换：

```bash
GATEWAY_MODE=proxy        # .env 设一次，之后照常 php artisan gateway:start -d
# 或临时覆盖：php artisan gateway:start -d --mode=proxy
```

| 形态 | 开关 | 网关行为（自动推导，可被 GATEWAY_HOST/GATEWAY_SSL 覆盖） | 前端地址 |
|------|------|---------|---------|
| direct 直连（默认） | `GATEWAY_MODE=direct` | 监听 `0.0.0.0`；`GATEWAY_SSL=true` + 证书时以 wss 对外 | `GATEWAY_PUBLIC_WS_URL=wss://your-domain:1238` |
| proxy 反代（生产推荐） | `GATEWAY_MODE=proxy` | 只监听 `127.0.0.1` 明文（ssl 恒关闭），TLS 由 nginx 终结 | `GATEWAY_PUBLIC_WS_URL=wss://your-domain/im` |

nginx 反代示例（wss 走 443，转发到本机网关）：

```nginx
location /im {
    proxy_pass http://127.0.0.1:1238;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 60s;   # 须大于网关心跳间隔（当前 15s）
}
```

前端 WebSocket 地址由 blade 注入（`app.blade.php` → `window.GATEWAY_WS_URL`），不写死在任何 JS 里；已实测代理路径转发 + 握手 + 无效 token 拒绝全链路。

### 验证
- Node 22 原生 WebSocket 客户端：握手 → `{"type":"connected","user_id":4}`（token 鉴权 + bindUid）
- 全链路：客服回复 `@xiaoming …` → WebSocket 秒级送达对应客户端（event=mentioned，含工单号/标题）
- 浏览器实测：推送后未读角标实时 +1、toast 弹出

### 顺带修复
- `?mine=1`（只看我受理的）筛选自 v1.0 起把参数值 1 直接当作 agent_id 使用（实际筛的是"受理人是 ID 1 的工单"），已改为后端换算当前用户 id，并补回归测试

## 19. 移动客户端（v2.5）

产品分层：**普通用户（客户）用移动客户端，管理员/客服用桌面后台**——同一套代码按角色分流（一个域名）。

- 客户端路由 `/m/*`（`MLayout`）：顶栏（logo+铃铛+头像）+ 底部 Tab（我的工单/提工单/知识库/我的）
- 工单列表为卡片式（状态/超时/优先级标签、标题、工单号、时间、回复数），状态 chips 横滑筛选（全部/待处理/处理中/待确认/已关闭），"加载更多"分页
- 详情/新建/知识库复用桌面组件（详情页聊天式回复、@提及、附件在移动端单栏可用）
- "我的"页：头像、昵称/@账号、邮箱、工单统计、退出登录
- 路由守卫分流：`user` 角色登录后强制进入 `/m/tickets`（访问 `/admin/*` 自动弹回）；`agent/admin` 进入 `/admin/*` 桌面后台（也可访问 /m）；登录/注册成功按角色跳转
- **URL 布局（v2.7）**：`/{ADMIN_PATH}/*` 管理后台 · `/m/*` 移动客户端 · `/login` 共用；根路径 `/` 按角色自动分流
- **登录入口分离（v2.8）**：`/login` 移动端仅普通用户可登录/注册（staff 账号登录会被拒绝并提示走后台入口）；`/{ADMIN_PATH}/login` 后台专属登录页仅客服/管理员（普通用户被拒提示）；未登录访问后台自动送后台登录页；后台手机端菜单改为底部 Tab
- **提交工单页移动端输入放大（v2.8）**：输入 16px 字号（防 iOS 聚焦缩放）、标题框 46px、文本域 ≥140px、按钮 48px
- **后台路径可配置且默认不为 admin（v2.7）**：`.env` 的 `ADMIN_PATH`（默认 `panel`，建议上线改为随机串如 `x7k2p9`）经 blade 注入 `window.ADMIN_PATH`，前端 `paths.js` 统一构造（adminPath/ticketPath/homePath）——改路径无需重新构建前端；`/admin/*` 等未知路径一律兜底送去 `/login`（不暴露后台存在）；未匹配路径统一 404→登录页
- 踩坑记录：TicketCreateView 曾引用未声明的 `auth`（ReferenceError 被 catch 吞掉表现为"提交成功但不跳转"）——script setup 里用变量必须先声明

## 20. 数据库：已切换 MySQL（2026-10-25）

当前 `.env` 使用本机 ServBay MySQL 8.0（127.0.0.1:3306，库 `ticket`，utf8mb4），已 `migrate:fresh --seed` 并验证读写。切回 SQLite：把 `.env` 改回 `DB_CONNECTION=sqlite` 即可。

邮件真实发送：把 `.env` 中 `MAIL_MAILER=log` 改为 `smtp` 并配置 `MAIL_HOST/PORT/USERNAME/PASSWORD`，通知邮件即真实发出。

### @提及
- 提及匹配升级：`@xxx` 优先匹配**账号**（username），也兼容昵称（name）；被提及者收到 "mentioned" 通知

## 16. 完善批次（v1.7）

- 新增 `GET /api/stats/mine`：当前用户各状态工单计数（登录即可用）
- 工单列表页顶部新增**可点击概览卡片**（全部/待处理/处理中/待确认/已关闭），点击即筛选列表并高亮当前项，计数随列表查询自动刷新
### 体验优化（v2.3 / v2.4）

v2.4 移动端适配（≤768px 断点）：
- 顶栏菜单折叠为右侧抽屉（菜单按钮），用户名隐藏只留头像
- 工单列表隐藏次要列（工单号/分类/回复数；≤480px 再隐藏创建时间），概览卡片紧凑化
- 详情页双栏（回复区/操作区）在移动端单栏堆叠（:xs=24 :sm），聊天气泡放宽到 88%
- 登录页移动端隐藏品牌区只留表单；看板卡片 xs=12 两列堆叠
- 实测 iPhone 视口（390×844）：列表/抽屉菜单/新建/详情均无横向滚动

v2.3：
- @提及面板键盘导航：↑↓ 移动高亮、Enter 选中插入、Esc 关闭（监听器绑在原生 textarea 上——el-input 的 attrs 不保证透传到 inner textarea）
- 新通知声音提醒：Web Audio 合成双音提示（`utils/sound.js`，无需音频文件，浏览器自动播放限制下首次交互后生效）

- 回复框占位文案提示 `@账号 或 @昵称 可提醒相关同事`
- 提及匹配升级：`@xxx` 优先匹配**账号**（username），也兼容昵称（name）

## 17. @提及自动补全（v1.8）

- 新增 `GET /api/mention-search?q=`：按账号/昵称模糊搜索人员（登录即可用，最多 8 条）
- 回复框输入 `@` 后弹人员选择面板（头像 + 昵称 + @账号 + 角色标签），键盘 Esc 关闭；选中自动把已输入的 `@片段` 替换为 `@账号 `，光标落位
- users 表新增 `phone`（唯一、可空）字段，管理员用户页展示手机号列——为后续短信登录/通知预留
- ⚠️ 坑：中文正则在前端要用 `[\u4e00-\u9fa5]` 而不是 PHP 风格的 `\x{4e00}`（后者在 JS `/u` 模式下是 SyntaxError，会让整个路由组件加载失败白屏）

## 15. 账号体系与 UI 翻新（v1.6）

### 账号登录
- users 表新增 `username`（唯一，2-30 位中文/字母/数字/下划线），`email` 改为可空——**注册不再强制邮箱**（用户乙即为无邮箱账号）
- 登录接口改为 `account` 字段：用户名或邮箱均可登录（老邮箱用户无缝兼容）
- 演示账号改为用户名：admin / zhang / li / xiaoming / xiaohong（密码均为 password）

### UI 翻新
| 页面 | 变化 |
|------|------|
| 登录页 | 分屏设计：左侧品牌区（渐变底 + 特性介绍），右侧表单卡；注册表单改为 昵称+账号+密码，邮箱选填 |
| 顶栏 | 用户头像（按名字哈希取色的首字圆形头像，`utils/avatar.js`） |
| 工单列表 | 提交人/受理客服列带头像 |
| 工单详情 | 回复改为聊天式气泡：自己发的靠右蓝色，客服/他人靠左带头像与角色标签 |
| 统计看板 | 用 ECharts 替换 div 柱图：7 天趋势平滑面积线 + 状态/优先级环形图，自动适配暗色与窗口缩放 |

## 12. 暗黑模式（v1.4）

- 机制：Element Plus 官方 dark 主题（`html.dark` class + `dark/css-vars.css`），组件自动适配；自定义样式（顶栏、回复气泡、趋势柱状图等）在 `app.css` 中按 `html.dark` 覆盖
- 入口：顶栏太阳/月亮图标按钮（登录页右上角也有），即时切换
- 偏好存储：`localStorage.ticket_dark`；首次访问跟随系统 `prefers-color-scheme`，手动切换后记住选择

### 约定
- 统一响应：数据直接返回资源 JSON，校验错误 422 `{message, errors}`，未认证 401，无权限 403。
- 分页：`{data, meta:{current_page, total, ...}}`（Laravel 默认格式）。

## 5. 后端结构

```
app/
  Http/Controllers/Api/
    AuthController.php
    TicketController.php
    ReplyController.php
    CategoryController.php
    StatsController.php
  Http/Requests/          # 表单校验
  Http/Resources/         # 资源转换（TicketResource 等）
  Models/                 # User, Category, Ticket, TicketReply
  Policies/               # TicketPolicy（查看/回复/更新权限）
routes/api.php            # 全部 API 路由
```

- 权限判断用 Laravel Policy，不散落在控制器里。
- 工单号 `TK-Ymd-序号` 在创建时生成。
- 回复时：若工单 open 且是 agent/admin 回复 → 自动置为 in_progress。

## 6. 前端页面（Vue 3 + Vue Router + Pinia + Element Plus）

| 路由 | 页面 | 说明 |
|------|------|------|
| /login | 登录/注册 | 二合一卡片，切换 Tab |
| / | 工单列表 | 筛选（状态/优先级/分类/关键字/只看我的），分页表格 |
| /tickets/new | 新建工单 | 标题、分类、优先级、内容 |
| /tickets/:id | 工单详情 | 工单信息、时间线（回复列表 + 回复框）、状态操作（agent） |
| /dashboard | 统计看板 | agent/admin：状态/优先级卡片 + 7 天趋势图 |
| /categories | 分类管理 | admin |

布局：顶部导航（Logo、菜单、用户下拉/退出），Element Plus 组件。

前端构建走 Laravel Vite（`resources/js`，入口 `app.js` + `App.vue`），Blade 单页壳 `resources/views/app.blade.php`，未登录跳 `/login`。

## 7. 非功能

- 表单校验前后端双重校验（后端 FormRequest 为准）。
- 密码 bcrypt，Sanctum token 明文只在响应中出现一次。
- 时间统一 `date:Y-m-d H:i` 前端格式化（dayjs 可选，v1 直接用原生格式化）。

## 8. 实施步骤

1. `composer create-project laravel/laravel`（13.x）+ Sanctum + CORS 配置
2. 迁移 + 模型 + 工厂/种子（3 角色、若干分类、演示工单与回复）
3. API 实现（控制器 + Policy + 路由）
4. Vue 前端（路由、Pinia auth store、页面）
5. `php artisan migrate --seed` → `npm run build` → 启动 `php artisan serve` 端到端验证

## 9. 事件与通知（v1.1）

| 事件 | 站内信/邮件对象 | 说明 |
|------|----------------|------|
| 创建工单 | 全部客服/管理员 | "xx 提交了新工单" |
| 客服回复 | 工单提交人 | 内容预览 |
| 用户回复 | 受理客服（未指派则全部客服） | 内容预览 |
| 状态变更/指派 | 工单提交人（指派时另通知新客服） | 状态中文标签 |
| 客服回复待处理工单 | — | 状态自动流转 open → in_progress，并写日志 |

前端导航栏铃铛 30 秒轮询未读数，点击通知跳转对应工单并标记已读。

## 10. 验收标准

- 三种角色登录后看到的范围不同（user 只见自己工单）
- 创建 → 回复 → 状态流转（open→in_progress→pending_resolved→closed）全流程可走通
- agent 可指派/改优先级，admin 可管分类、删工单、看统计
- 列表筛选、分页正常
