# 工单系统差距分析（对标主流 Helpdesk / ITSM 产品）

> 分析对象：`/Users/a123/Desktop/code/ticket`（Laravel 13 + Vue 3 + Element Plus + GatewayWorker，当前 HEAD `2ae4876` / v2.16）
> 分析日期：2026-10-27
> 对标产品：Zendesk、Freshdesk、Jira Service Management、ServiceNow、Zammad、osTicket、GitLab Issues、Intercom / Front / Help Scout、Linear
> 分析目标：回答三个问题——**缺什么、补什么、优化什么**

---

## 0. 一句话结论

现有系统已经是一个**闭环完整、工程质量在线的单团队 Web 工单工具**：提交 → 分派 → 回复 → SLA 提醒 → 关闭 → 评价 → 统计，主链路全部跑通，还有实时推送、移动端、审计页、56 个 API 测试和 Playwright 冒烟。

但把它放到主流产品旁边看，差距不在"页面多少"，而在**五层平台能力**：

1. **业务上下文**：工单里看不到客户是谁、买了什么、什么版本——对外售后最大的效率黑洞（§11.1-A）；
2. **协作模型**只有"公开对话"，缺内部备注、关注者、合并、关联、冲突提示——客服之间无法真正协同；
3. **服务管理**只有"首次响应 SLA"，缺解决时限、营业时间、暂停、升级链、按策略分级——管不住服务质量；
4. **自动化引擎**基本为零，缺触发器、宏、分配规则、模板——坐席效率上不去，规模一大就靠人肉；
5. **渠道入口**只有 Web 表单，缺邮件入站、免登录建单、公开门户、自助知识库——客户触达面太窄。

> 定位已确认为**"自己产品的对外售后平台"（单租户、自用、小团队）**，最终范围见 **§12**（§11 是第一版，偏重平台化，已被简化）。ITIL 相关的审批/资产/问题管理、多租户、计费、资金操作均不在范围内。

另外在代码层发现了 **6 个必须先修的正确性/安全问题**（其中 1 个是越权读取他人工单回复），详细见第 7 节。

---

## 1. 成熟度评分（1–5，5 = 对标商业产品）

| 维度 | 分数 | 判断依据 |
|---|---:|---|
| 工单核心闭环 | 4.0 | 创建/回复/状态/指派/评价/附件/导出全通 |
| 协作与内部沟通 | 2.0 | 有回复和 @提及，无内部备注/关注者/合并/关联 |
| 客户、组织与权限 | 2.0 | 3 个硬编码角色，无组织、无团队、无细粒度权限 |
| SLA 与服务管理 | 2.0 | 仅首次响应时限，无解决时限/营业时间/暂停/升级 |
| 自动化与坐席效率 | 1.0 | 无触发器、无宏、无模板、无自动分配 |
| 渠道与触点 | 1.5 | 仅 Web 表单；无邮件入站、无公开门户、无聊天 |
| 知识库与自助 | 2.0 | 登录后可见的 FAQ，无搜索/反馈/公开页/工单转文章 |
| 通知与实时通信 | 3.0 | WebSocket + 站内信 + 邮件 + 队列，但无偏好/摘要 |
| 搜索、视图与导航 | 1.5 | 标题/工单号 LIKE，筛选不可保存，无全文 |
| 报表与分析 | 2.0 | 计数 + 7 天趋势 + 坐席绩效，无区间/合规率/FCR/账龄 |
| 安全与合规 | 2.0 | 有登录限流/滑块/审计，无 2FA/SSO、token 永不过期 |
| 集成与开放平台 | 1.0 | 只有内部 SPA 用的 API，无 Webhook/API Token/OpenAPI |
| 性能与可运维 | 3.0 | 结构清晰、队列化、健康检查；缺索引/缓存/监控/CI |
| 前端体验与可访问性 | 3.0 | 界面成熟、暗黑模式、移动端；缺 i18n/快捷键/A11y |
| 工程质量 | 3.0 | 测试扎实、分层清楚；无 CI、无静态分析、无覆盖率门禁 |
| **加权均分** | **2.2** | **"优秀的小团队内部工具"，尚未成为"服务台平台"** |

---

## 2. 现有能力盘点（已经做得好的）

**认证与账号**
- 用户名/邮箱双入口登录、注册、Sanctum Token、3 角色（user/agent/admin）
- 后台独立登录页 + 滑块行为验证 + 一次性票据 + 登录限流
- 管理员建号、重置密码并吊销全部 token

**工单核心**
- 4 状态（open/in_progress/pending_resolved/closed）、4 优先级、分类、指派
- 按优先级计算响应 SLA、首次响应时间、超时筛选与提醒命令
- 满意度评分 + 评语、附件上传下载（图片预览）、操作日志、CSV 导出
- 批量关闭 / 批量指派（≤50 张）、审计流水页

**协作与实时**
- 聊天式回复、`@账号/昵称` 提及 + 键盘导航补全
- GatewayWorker WebSocket 推送：新回复进聊天区、通知铃铛实时 +1、提示音
- 站内信 + 邮件 + 实时三通道，通知队列化（邮件不阻塞请求）

**分析与客户端**
- 看板：状态/优先级分布、7 天趋势、坐席绩效（受理/关闭/平均首响/满意度）
- 移动端 `/m/*` 客户端（Tab、卡片列表、状态 chips、我的页）
- 暗黑模式、响应式、路由按角色分流、后台路径可配置

**工程**
- Policy 集中鉴权、Resource 统一输出、FormRequest 校验（部分内联）
- 56 个 API 测试 / 234 断言、14 个 Playwright 用例
- 设计文档非常完整（`docs/DESIGN.md`）

---

## 3. 对标矩阵

图例：✅ 完整；🟡 基础/部分；❌ 缺失。

| 能力 | 本系统 | Zendesk / Freshdesk | Jira SM / ServiceNow | Zammad / osTicket | 差距 |
|---|:--:|:--:|:--:|:--:|:--:|
| 工单创建 / 回复 | ✅ | ✅ | ✅ | ✅ | — |
| 固定状态流转 | 🟡 | ✅ | ✅ | ✅ | 中 |
| 自定义状态 / 工作流 | ❌ | ✅ | ✅ | 🟡 | 高 |
| 分类 | 🟡（单层） | ✅（多级） | ✅ | ✅ | 中 |
| 标签 | ❌ | ✅ | ✅ | ✅ | 高 |
| 自定义字段 / 请求类型 | ❌ | ✅ | ✅ | ✅ | 高 |
| **内部备注（客户不可见）** | ❌ | ✅ | ✅ | ✅ | **高** |
| **工单合并 / 关联 / 父子** | ❌ | ✅ | ✅ | 🟡 | **高** |
| **关注者 / CC** | ❌ | ✅ | ✅ | ✅ | **高** |
| 客户档案 / 组织 | ❌ | ✅ | ✅ | ✅ | 高 |
| 团队 / 队列 / 技能路由 | ❌ | ✅ | ✅ | ✅ | 高 |
| SLA：首次响应 | 🟡 | ✅ | ✅ | ✅ | 中 |
| **SLA：解决时限 / 营业时间 / 暂停 / 升级** | ❌ | ✅ | ✅ | ✅ | **高** |
| 审批流 | ❌ | 🟡 | ✅ | ❌ | 低（视定位） |
| **触发器 / 自动化规则** | ❌ | ✅ | ✅ | ✅ | **高** |
| **宏 / 快捷回复 / 模板** | ❌ | ✅ | ✅ | ✅ | **高** |
| **邮件入站（Email → 工单）** | ❌ | ✅ | ✅ | ✅ | **高** |
| 客户门户 / 自助提交 | 🟡 | ✅ | ✅ | ✅ | 高 |
| 多渠道（聊天/电话/社交） | ❌ | ✅ | 🟡 | 🟡 | 高 |
| 知识库 | 🟡 | ✅ | ✅ | ✅ | 中 |
| KB 搜索 / 反馈 / 工单转文章 | ❌ | ✅ | ✅ | 🟡 | 高 |
| CSAT 满意度 | 🟡（站内） | ✅ | ✅ | ✅ | 中 |
| 满意度自动调查 | ❌ | ✅ | ✅ | ✅ | 中 |
| 报表 / 自定义报表 | 🟡 | ✅ | ✅ | 🟡 | 高 |
| 保存视图 / 队列视图 | 🟡（临时筛选） | ✅ | ✅ | ✅ | 高 |
| 全文搜索 | ❌ | ✅ | ✅ | 🟡 | 高 |
| 通知偏好 / 摘要 | ❌ | ✅ | 🟡 | 🟡 | 中 |
| 实时协作 / 冲突提示 | 🟡（仅推送） | ✅ | 🟡 | 🟡 | 中 |
| 2FA / SSO | ❌ | ✅ | ✅ | 🟡 | 高 |
| 细粒度权限 | ❌ | ✅ | ✅ | ✅ | 高 |
| 审计日志 | 🟡 | ✅ | ✅ | ✅ | 低 |
| 软删除 / 数据保留 | ❌ | ✅ | ✅ | 🟡 | 高 |
| 开放 API / Webhook | ❌ | ✅ | ✅ | ✅ | 高 |
| 多语言 / 时区 | ❌ | ✅ | ✅ | ✅ | 中 |
| 移动 App / 推送 | ❌ | ✅ | 🟡 | 🟡 | 中 |
| AI 辅助（摘要/起草/分类） | ❌ | ✅ | 🟡 | 🟡 | 中 |
| 时间追踪 / 工时 | ❌ | 🟡 | ✅ | 🟡 | 低 |
| 多租户 / 多品牌 | ❌ | ✅ | ✅ | 🟡 | 低（视定位） |
| 附件预览 | 🟡（仅图片） | ✅ | ✅ | ✅ | 低 |
| 键盘快捷键 / 命令面板 | ❌ | ✅ | ✅ | ✅ | 中 |
| 可访问性（A11y） | 🟡 | ✅ | 🟡 | 🟡 | 中 |

---

## 4. 缺什么：15 个维度的差距分析

### D1. 工单生命周期与状态机

**现状**：固定 4 状态，`PUT /tickets/{id}` 可任意跳转（closed → open 也放行），`resolved_at/closed_at` 只在进入对应状态时写入、**回退时不清空**；客户在 closed 后无法补充信息或重开；`pending_resolved` 不会自动关闭。

**缺什么**
- 状态机（合法迁移表），而不是枚举随便改
- `reopen`（客户在 N 天内重开，生成重开次数与原因）
- `closed_reason`（已解决 / 重复 / 无法复现 / 不修复 / 垃圾）
- `pending_customer` 与 `pending_internal` 的区分（谁在等谁）
- 自动关闭：`pending_resolved` 满 72h 提醒、满 7 天关闭
- `type`：咨询 / 故障 / 投诉 / 需求 / 任务（ITIL-lite）

**参考**：Zendesk 的 "Solved → Closed" 双阶段；Jira SM 的 workflow + transition conditions；osTicket 的 "待客户回复自动关闭"。

**优先级**：P1（P0 只需修时间戳回退与非法迁移）

---

### D2. 协作与内部沟通（最大缺口）

**现状**：只有一种回复，客户和客服看到同一份内容；有 @提及；无内部备注、无关注者、无合并、无关联、无"谁正在看"。

**缺什么**
- **内部备注（internal note）**：客服之间讨论，客户永远不可见——这是所有主流产品的第一协作原语
- **关注者 / CC**：客户同事、主管、售前可围观并收到通知
- **合并工单**：重复工单合并到主单，回复与附件迁移，原单标 duplicate
- **关联工单**：related / blocks / duplicate / parent-child（一次故障影响多客户）
- **冲突提示**：同一工单有人正在输入/回复时提示（Front/Zendesk 的 collision detection）
- **消息编辑与撤回**、已读回执、回复分页
- **侧边讨论（side conversation）**：把内部专家拉进工单而不暴露给客户

**数据层影响**：`ticket_replies.is_internal`、`attachments.reply_id`、`ticket_links`、`ticket_watchers`、`tickets.merged_into_id`。

**优先级**：P1（内部备注 + 关注者 + 关联/合并是"能不能多人协作"的分水岭）

---

### D3. 客户、组织与权限

**现状**：`users` 一张表既是登录账号也是客户资料；3 个硬编码角色；无组织、无团队、无客户档案页；管理员只能看用户列表和工单计数。

**缺什么**
- **客户档案（Customer 360）**：一个客户的全部工单、历史评分、联系方式、组织、备注、活跃度
- **组织（Organization）**：B2B 场景下同一公司多联系人共享工单、组织级 SLA、组织级报表
- **团队 / 队列（Team/Queue）**：按分类或产品线分组，组内可见、组间隔离
- **细粒度 RBAC**：角色 → 权限点（查看全部/本组、改状态、改优先级、删除、导出、配置自动化、查看报表），而不是 `role === 'admin'`
- **只读角色**（审计/主管）、**轻量坐席**（Light Agent，只能看被 @ 的工单）
- **账号状态**：启用/禁用、锁定、离职交接（工单批量转交）

**现状风险**：`users` 表无 `status` 字段；删除用户会 `cascade` 删除其全部工单（`database/migrations/2026_10_24_000002_create_tickets_table.php:24`），客户注销即数据丢失。

**优先级**：P2（团队/权限），P1（账号禁用 + 工单转交）

---

### D4. SLA 与服务管理

**现状**：创建时按优先级写死 `sla_due_at`（紧急 4h / 高 8h / 中 24h / 低 72h），只在首次响应时记录 `first_response_at`；超时命令每 5 分钟扫一次并通知受理人/全体客服。

**缺什么**
- **解决时限 SLA**（resolution SLA），不只是响应
- **多套 SLA 策略**：按优先级 × 分类 × 客户/组织等级匹配，而不是全局常量
- **营业时间 / 节假日日历**：SLA 只在工作时段计时（当前 4h 是自然小时，夜间提交必超时）
- **SLA 暂停**：等待客户回复（pending_customer）时暂停计时，恢复后继续
- **升级链（escalation）**：50% 预警 → 100% 通知主管 → 超时 2h 升级到团队负责人
- **按 SLA 状态筛选**：即将超时（<1h）、已超时、已暂停
- **SLA 合规率报表**

**逻辑缺陷**：`Ticket::isOverdue()`（`app/Models/Ticket.php:56-61`）不判断 `first_response_at`，导致"已响应但未关闭"的工单永远显示超时；同时优先级变更不会重算 `sla_due_at`（`app/Http/Controllers/Api/TicketController.php:88-96`）。

**参考**：Zendesk SLA policies（policy × priority × group × channel + business hours + pause）；Jira SM SLA（calendar + JQL + escalation）。

**优先级**：P1（营业时间 + 解决时限 + 暂停），P2（升级链 + 多策略）

---

### D5. 自动化与坐席效率

**现状**：零自动化。新建工单通知全体客服；指派靠人手动选；回复靠手打；没有模板。

**缺什么**
- **触发器（Triggers）**：工单创建/更新时满足条件 → 执行动作
  - 标题含"发票/退款" → 分类=账单、优先级=高、指派给财务组
  - 优先级=紧急 → 通知团队主管
  - 客户回复 → 状态自动 in_progress
- **宏（Macros）**：一键"回复 + 改状态 + 打标签 + 指派"，坐席高频操作从 5 步变 1 步
- **快捷回复 / 模板（Canned responses）**：按分类维护标准话术，支持变量 `{{customer.name}}`
- **自动分配**：轮询（round-robin）、按负载（least busy）、按技能/分类、按在线状态
- **工单模板 / 引导表单**：按分类展示不同字段，降低客户表达成本
- **重复检测**：提交时按标题/内容相似度提示"可能重复的工单"
- **批量改优先级/分类/标签**（目前只能批量关闭/指派）

**优先级**：P1（宏 + 快捷回复，投入小见效快），P2（触发器 + 自动分配）

---

### D6. 渠道与触点

**现状**：只有 SPA 表单；登录后才能提交；FAQ 登录后才可见。

**缺什么**
- **邮件入站**：`support@domain` 收信 → 建单/追加回复，按 `Message-ID / In-Reply-To` 线程化；这是工单系统最刚需的入口
- **邮件出站**：品牌化模板、`Reply-To: ticket+{id}@domain`、退订链接、真实 SMTP + SPF/DKIM
- **公开门户**：未登录提交（邮箱验证或工单号+邮箱查询）、公开知识库（SEO）、提交前先搜 KB（deflection）
- **Web 聊天 / IM 组件**：网站右下角聊天 → 工单
- **API / 嵌入式表单**：第三方系统直接建单
- **电话/语音留言**（可选）

**参考**：Zammad/osTicket 是"邮件优先"架构；Zendesk/Freshdesk 全渠道；GitLab Service Desk 用邮件建 Issue。

**优先级**：P1（邮件入站 + 公开门户），P3（聊天/电话）

---

### D7. 知识库与自助服务

**现状**：`faqs` 表（问题/答案/排序），登录后可看，管理员维护，无分类、无搜索、无统计。

**缺什么**
- 文章模型升级：分类、slug、富文本、发布状态、版本、作者、浏览量、有帮助/没帮助
- 全文搜索 + 相关推荐（提交工单时按标题推荐文章）
- 工单 → 文章（结案时一键沉淀）
- 文章反馈闭环（差评文章进入待优化列表）
- 多语言 KB
- 公开访问（不登录也能看，SEO 引流）

**优先级**：P2

---

### D8. 通知与实时通信

**现状**：database + mail + Gateway 三通道；新工单通知全部客服；回复通知对方；提及通知；30s 轮询兜底。

**缺什么**
- **通知偏好**：按用户 × 事件 × 通道开关（站内/邮件/实时），静默时段，摘要（每日/每周）
- **去重与聚合**：同一工单连续 5 条回复合并为一封邮件
- **升级通知**：SLA 超时通知主管，而不只是受理人
- **移动推送 / PWA**（现在关掉页面就收不到）
- **邮件模板与品牌**、多语言、退订
- **通知失败可观测**：队列失败无告警，`failed_jobs` 无人看
- **WebSocket 鉴权安全**：token 放在 URL query（`resources/js/realtime.js:24`），会进 nginx access log / 浏览器历史；应改为短期 ticket 或 `Sec-WebSocket-Protocol`

**优先级**：P1（偏好 + 聚合），P2（推送 + 模板）

---

### D9. 搜索、视图与导航

**现状**：列表只按 `title/no` 做 `LIKE %kw%`；筛选条件不保存；固定按 `created_at desc`；无全局搜索；无快捷键。

**缺什么**
- **全文搜索**：标题、内容、回复、客户名、工单号、标签、附件名；MySQL `FULLTEXT` + ngram（中文）或 Meilisearch/Typesense
- **保存视图（Saved Views）**：我的待办、未指派紧急、即将超时、我参与的、本周已关闭；支持共享给团队
- **排序与列配置**：按优先级/更新时间/SLA 剩余排序，自定义列
- **全局命令面板 `Ctrl/Cmd + K`**：跳工单、搜客户、执行动作
- **最近访问 / 相关工单推荐**

**优先级**：P1（保存视图），P2（全文搜索 + 命令面板）

---

### D10. 报表与分析

**现状**：状态/优先级计数、7 天新增趋势、坐席绩效表（受理/关闭/平均首响/满意度）、评价明细。

**缺什么**
- **时间区间**（今天/本周/本月/自定义）——现在固定 7 天
- **服务指标**：SLA 合规率、首次响应中位数/P90、解决时长、积压账龄（0-1d/1-3d/3-7d/7d+）、重开率、FCR（一次解决率）、CSAT 趋势、渠道分布
- **坐席维度**：工作量/在办数、解决量、响应分布、满意度趋势、质检评分
- **客户维度**：Top 客户、组织工单量、客户满意度
- **自定义报表 / 定时邮件报告 / 导出 Excel**
- **实时看板**：今日待办、即将超时、未指派

**性能问题**：`StatsController` 对每个客服把其全部工单加载到 PHP 算平均响应（`app/Http/Controllers/Api/StatsController.php:43-45`），O(坐席数 × 工单数)，数据量一大就内存/耗时爆炸；应下推为 SQL 聚合或预计算日表。

**优先级**：P2（先补区间 + SLA 合规 + 账龄），P3（自定义报表）

---

### D11. 安全、合规与审计

**现状**：bcrypt、Sanctum Token、登录限流、后台滑块、操作日志（自由文本 detail）、附件类型白名单。

**缺什么**
- **2FA（TOTP）** 与 **SSO（SAML/OIDC）**；企业采购的硬门槛
- **Token 过期**：`config/sanctum.php:53` 为 `null`，token 永久有效；无"退出所有设备"
- **自助找回密码**：目前只能管理员重置，员工离职/忘记密码成本高
- **邮箱验证**、注册防刷（当前注册无验证码/邀请制）
- **登录审计**：IP、UA、时间、失败原因；异常登录提醒
- **账号禁用/锁定**（离职、风控）
- **附件安全**：仅 MIME 白名单，无病毒扫描、无图片重编码（polyglot 风险）、无签名 URL、无下载审计
- **数据保留与删除**：无软删除、无归档、无 GDPR 导出/匿名化；删除工单级联删除日志与附件，审计不可追溯
- **PII 保护**：手机号/邮箱在列表与日志中明文；无字段级脱敏
- **安全响应头 / CSP**、CSRF（token 模式已规避）、依赖漏洞扫描

**优先级**：P0（越权、token 过期、软删除），P2（2FA/SSO、找回密码、登录审计）

---

### D12. 集成与开放平台

**现状**：只有内部 SPA 使用的 `/api/*`，无版本号、无文档、无 Webhook、无第三方 App/API Token。

**缺什么**
- **Webhook**：工单创建/更新/关闭时回调外部系统（订单、CRM、IM），带签名与重试
- **API Token**：给第三方服务用的长期凭证（带 scope、可吊销），与用户 token 隔离
- **OpenAPI/Swagger 文档** + 沙箱
- **预置集成**：Slack/Teams 通知、企业微信/钉钉、Jira/GitHub 双向同步、订单系统查单
- **API 版本化 `/api/v1`**、幂等键、乐观锁（`If-Match`）、游标分页、统一错误码

**优先级**：P2

---

### D13. 性能、架构与可运维

**现状**：单机 Laravel + MySQL + GatewayWorker；队列 database；无 Redis；无 CI/CD；无监控告警。

**缺什么 / 风险**
- **索引**：`tickets` 缺 `(status, created_at)`、`(agent_id, status)`、`sla_due_at`、`user_id, created_at`；`LIKE %kw%` 无法走索引；`replies.ticket_id` 有 FK 索引但无 `(ticket_id, created_at)`
- **N+1 / 大对象**：详情页一次加载全部回复+日志+附件，长工单会拖慢；`user` 列表无分页
- **缓存**：分类、坐席列表、统计结果可缓存 60s；现在每次全查
- **队列**：生产需 supervisor 托管 `queue:work`，仓库无配置；无失败任务监控
- **实时层**：GatewayWorker 单机，无法水平扩展；多节点需 Redis/Register 集群；token 走 query
- **附件**：本地磁盘，无 S3/对象存储、无缩略图、大文件下载占内存
- **可观测**：无 Sentry/结构化日志/慢查询告警/业务指标
- **交付**：无 `.github/workflows`、无 Dockerfile/compose、无部署 runbook、无备份恢复演练
- **健康检查**：`/up` 已有，但无依赖检查（DB/队列/网关）

**优先级**：P1（索引 + per_page 上限 + 队列托管说明），P2（缓存 + 监控 + CI）

---

### D14. 前端体验、可访问性与移动端

**现状**：Element Plus + 暗黑模式 + 响应式 + 移动端 Tab 客户端，观感不错。

**缺什么**
- **内部备注切换**（公开回复 / 内部备注）
- **SLA 倒计时徽标**、工单状态看板（Kanban）
- **客户 360 侧栏**、关联工单、关注者头像
- **乐观更新 / 骨架屏 / 错误边界**：现在发送后整页 `load()`，长列表会闪
- **快捷键与命令面板**、批量改任意字段
- **国际化与 per-user 时区**：全站硬编码中文，时间按服务器时区展示
- **可访问性**：自定义可点击 `div`（chips、卡片）无 `role/tabindex`，状态仅靠颜色区分，焦点管理缺失
- **PWA / 移动推送 / 离线草稿 / 下拉刷新**
- **富文本或 Markdown**：现在是纯 textarea，代码块/表格/内联图体验弱

**优先级**：P1（内部备注 UI、SLA 倒计时），P2（i18n、A11y、PWA）

---

### D15. 工程质量与交付

**现状**：56 个 API 测试、14 个 E2E、分层清晰、设计文档详尽。

**缺什么**
- **CI**（无 `.github/workflows`）：测试、Pint、构建、E2E 都不在流水线
- **静态分析**：Larastan/PHPStan、ESLint、TypeScript（现在纯 JS + `script setup`）
- **覆盖率门禁**、**契约测试**（前后端字段漂移）
- **负载测试**（列表/详情/统计在 10 万工单下的表现）
- **E2E 可 CI 化**：现在依赖本地已启动服务与演示库，无 `webServer` 配置
- **可访问性测试**（axe）、**视觉回归**
- **API 文档**（OpenAPI 自动生成）、**ADR**、**运维手册**

**优先级**：P2

---

## 5. 补什么：建议的数据模型与接口清单

### 5.1 表结构增量（按依赖顺序）

```
-- 1) 工单主表扩展
tickets
  + type              string(20)   question|incident|problem|task
  + source            string(20)   web|email|chat|api|phone
  + team_id           FK teams NULL
  + last_activity_at  timestamp index
  + resolution_due_at timestamp NULL
  + sla_paused_at     timestamp NULL
  + paused_seconds    unsigned int default 0
  + reopen_count      unsigned smallint default 0
  + closed_reason     string(30) NULL
  + merged_into_id    FK tickets NULL
  + tags              JSON NULL            -- 或独立 tags + ticket_tag
  + custom_fields     JSON NULL
  + deleted_at        softDeletes

-- 2) 回复：公开/内部二分
ticket_replies
  + is_internal  boolean default false  index
  + reply_to_id  FK ticket_replies NULL
  + edited_at    timestamp NULL
  + deleted_at   softDeletes

-- 3) 附件挂到具体消息
attachments
  + reply_id    FK ticket_replies NULL
  + checksum    string(64) NULL
  + scan_status string(20) default 'pending'

-- 4) 审计升级为不可变事件流
ticket_logs  →  ticket_events
  + event       string(40)
  + before      JSON NULL
  + after       JSON NULL
  + ip          string(45) NULL
  + user_agent  string(255) NULL

-- 5) 用户与组织
users
  + status        string(20) default 'active'   -- active|disabled|locked
  + timezone      string(64) NULL
  + locale        string(10) NULL
  + organization_id FK organizations NULL
  + last_login_at timestamp NULL
  + last_login_ip string(45) NULL
  + two_factor_secret / two_factor_confirmed_at
  + deleted_at    softDeletes

organizations (id, name, domain, sla_policy_id, created_at)

-- 6) 团队与路由
teams (id, name, category_id NULL, sla_policy_id NULL)
team_members (team_id, user_id, role)

-- 7) SLA 策略化
sla_policies (id, name, active, business_hours_id, position)
sla_targets  (id, sla_policy_id, priority, first_response_minutes, resolution_minutes)
business_hours (id, name, timezone, mon..sun 的起止, holidays JSON)

-- 8) 自动化
macros   (id, name, actions JSON, team_id NULL, visibility, position)
triggers (id, name, event, conditions JSON, actions JSON, active, position)
saved_views (id, user_id, team_id NULL, name, filters JSON, shared)

-- 9) 协作
ticket_links    (id, ticket_id, linked_ticket_id, type)   -- related|duplicate|blocks|parent
ticket_watchers (id, ticket_id, user_id, reason)
tags (id, name) + ticket_tag (ticket_id, tag_id)

-- 10) 知识库升级
kb_categories (id, name, slug, position)
kb_articles   (id, category_id, slug, title, body, locale, published_at,
               author_id, views, helpful_yes, helpful_no)
-- faqs 迁移为 kb_articles

-- 11) 满意度调查
csat_surveys (id, ticket_id, sent_at, responded_at, score, comment, channel)

-- 12) 邮件通道
inbound_emails (id, message_id, in_reply_to, from_email, subject, raw_path,
                ticket_id NULL, processed_at, error)

-- 13) 开放平台
webhooks (id, url, events JSON, secret, active)
webhook_deliveries (id, webhook_id, event, payload, status, attempts, response_code)
notification_preferences (id, user_id, event, channel, enabled)
```

### 5.2 接口增量

```
PATCH  /api/tickets/{id}                      # 语义修正（现在用 PUT 做部分更新）
POST   /api/tickets/{id}/notes                # 内部备注
POST   /api/tickets/{id}/reopen
POST   /api/tickets/{id}/merge
POST   /api/tickets/{id}/links
DELETE /api/tickets/{id}/links/{link}
POST   /api/tickets/{id}/watchers
DELETE /api/tickets/{id}/watchers/{user}
POST   /api/tickets/{id}/assign-to-me
POST   /api/tickets/{id}/tags
GET    /api/tickets/{id}/events               # 不可变审计
GET    /api/search?q=&scope=tickets|kb|customers
GET    /api/views                             # 保存视图
POST   /api/views
GET    /api/queues/{team}/tickets             # 队列
POST   /api/tickets/{id}/apply-macro
GET    /api/macros
GET    /api/reports?from=&to=&group_by=
POST   /api/tickets/{id}/csat                 # 调查回填
POST   /api/webhooks
GET    /api/v1/...                            # 版本化
```

### 5.3 页面增量

| 页面 | 内容 |
|---|---|
| 工单详情（改造） | 公开回复/内部备注切换、关注者、关联工单、SLA 倒计时、客户 360 侧栏、宏下拉、冲突提示条 |
| 工单列表（改造） | 保存视图 Tab、列配置、排序、批量改优先级/分类/标签、密度切换 |
| 队列看板（新） | 按团队/状态的 Kanban，拖拽改状态 |
| 客户/组织详情（新） | 资料、全部工单、评分趋势、备注 |
| 报表中心（新） | 区间筛选、SLA 合规、账龄、FCR、CSAT 趋势、导出 |
| 自动化配置（新） | 触发器/宏/SLA 策略/分配规则的 CRUD |
| 公开门户（新） | 未登录提交、工单查询、KB 搜索与文章页 |
| 全局命令面板 | `Ctrl/Cmd+K` 搜索与跳转 |

---

## 6. 优化什么：现有功能的质量提升

### 6.1 正确性（详见第 7 节缺陷表）
- 修越权、token 过期、`per_page` 上限、SLA 判定、优先级变更重算、日志外泄、工单号竞态、状态回退时间戳

### 6.2 性能
- 补复合索引；`LIKE %kw%` 换全文索引（MySQL ngram）或 Meilisearch
- 统计下推 SQL / 预计算；坐席绩效缓存 5 分钟
- `per_page` 上限 100；回复分页 50；详情页按需加载日志
- 附件走对象存储 + 缩略图 + 流式下载
- 前端路由懒加载已具备，补 ECharts 按需引入、列表虚拟滚动、搜索防抖

### 6.3 体验
- 发送后局部追加而非整页刷新；骨架屏；空状态引导；失败重试
- 表单草稿自动保存（localStorage）
- SLA 剩余时间用倒计时而非静态时间
- 列表记住筛选条件与分页（回到列表不丢状态）
- 错误提示统一（现在混用 `e.response?.data?.message` 与固定文案）

### 6.4 安全
- 越权修复、token 过期与吊销、注册防刷、附件扫描、安全响应头、登录审计、PII 脱敏
- 管理后台路径可配置已做（`ADMIN_PATH`），建议再加 IP 白名单/二次验证选项

### 6.5 工程
- CI：`php artisan test` + Pint + `npm run build` + Playwright（`webServer` 自启）
- Larastan level 6、ESLint + Prettier、Vue TSC
- OpenAPI 生成 + 前端类型生成
- 结构化日志 + Sentry + 慢查询阈值
- Docker compose（app/queue/mysql/redis/gateway）+ 部署 runbook + 备份脚本

---

## 7. 已定位的具体缺陷（建议先修）

| # | 级别 | 位置 | 问题 | 影响 | 修复 |
|---|---|---|---|---|---|
| 1 | **P0 安全** | `app/Http/Controllers/Api/ReplyController.php:19-24`（路由 `routes/api.php:31`） | `index()` 没有 `authorize('view')` | 任意登录用户可读**任何工单**的全部回复（客户隐私/越权） | 方法首行加 `$this->authorize('view', $ticket);` |
| 2 | **P0 安全** | `config/sanctum.php:53` | `expiration => null`，token 永久有效 | 泄露的 token 永久可用；无"退出所有设备" | 设 7–30 天过期；改密/重置时吊销；提供撤销全部 |
| 3 | **P0 正确性** | `app/Models/Ticket.php:56-61` | `isOverdue()` 不看 `first_response_at` | 已响应但未关闭的工单永远显示"超时"；统计虚高 | 响应 SLA 仅在 `first_response_at === null` 时判定；另设解决 SLA |
| 4 | **P1 正确性** | `app/Http/Controllers/Api/TicketController.php:88-96` | 优先级变更不重算 `sla_due_at`；状态回退不清 `resolved_at/closed_at` | SLA 失真、时间记录矛盾 | 迁移状态时同步维护时间戳与 SLA |
| 5 | **P1 安全** | `app/Http/Controllers/Api/TicketController.php:66-73` + `resources/js/views/TicketDetailView.vue` | 详情把 `logs` 返回给普通用户并渲染 | 客户看到"指派给谁""SLA 超时"等内部操作 | 仅 staff 返回日志，或按 action 白名单过滤 |
| 6 | **P1 稳定性** | `app/Models/Ticket.php:102-108` | `max('no')` 生成序号，无锁、无事务 | 并发提交撞号 → 唯一键冲突 500；>9999 序号截断 | 独立日序号表 `SELECT ... FOR UPDATE`，或改用 ULID 后缀 |
| 7 | **P1 数据** | `app/Http/Controllers/Api/TicketController.php:215-220`；`database/migrations/2026_10_24_000002_create_tickets_table.php:24` | 硬删除；删除用户级联删工单 | 审计断链、客户注销丢数据 | `SoftDeletes`；用户外键改 `restrict`/`nullOnDelete` + 归档 |
| 8 | **P1 一致性** | `app/Http/Controllers/Api/TicketController.php:145-200` | 批量指派不发通知（单条指派会发）；批量关闭逐单通知但无事务 | 被指派客服不知道；部分失败不可回滚 | 批量指派补通知；整体包事务；失败项返回明细 |
| 9 | **P1 性能** | `app/Http/Controllers/Api/StatsController.php:43-45` | 每个客服加载其全部工单到 PHP 求平均 | O(坐席 × 工单)，数据量大时超时/爆内存 | SQL `AVG(TIMESTAMPDIFF(...))` 或预计算日表 + 缓存 |
| 10 | **P1 稳定性** | `app/Http/Controllers/Api/TicketController.php:37` | `per_page` 无上限 | 一次可拉全表，拖垮 DB/内存 | `min(max($v,1),100)` |
| 11 | **P2 体验** | `app/Notifications/TicketEventNotification.php:35-38` | 邮件深链 `/tickets/{id}` 无登录后回跳参数 | 未登录点开邮件 → 登录后落到首页，需重新找工单 | 带 `?redirect=`，登录成功后回跳 |
| 12 | **P2 安全** | `resources/js/realtime.js:24` | WebSocket token 放在 URL query | 进 access log / 历史 / Referer | 短期票据端点或 `Sec-WebSocket-Protocol` |
| 13 | **P2 体验** | `app/Http/Controllers/Api/ReplyController.php:21-23` | 回复不分页，一次全量 | 长工单详情慢 | 分页/游标加载 |
| 14 | **P2 安全** | `bootstrap/app.php`（全局） | 仅登录接口有限流；建单/回复/附件/注册无限流 | 刷单、附件轰炸、注册垃圾号 | 按用户+IP 加 `throttle`，注册加验证码/邀请码 |
| 15 | **P3 语义** | `routes/api.php:24` | 用 `PUT` 做部分更新 | 与 REST 语义不符，第三方集成易错 | 改 `PATCH`（保留 PUT 兼容） |

---

## 8. 路线图建议

### Sprint 0 —— 止血（2–3 人日，P0）
1. 修回复越权（#1）
2. token 过期 + 全端吊销（#2）
3. 修 `isOverdue` 与 SLA 重算（#3、#4）
4. 日志对客户隐藏（#5）
5. 工单号并发安全（#6）
6. 软删除 + 外键策略（#7）
7. `per_page` 上限 + 基础限流（#10、#14）

> 这一批不改产品形态，只消除"会出事"的点，建议优先合并。

### Sprint 1 —— 协作模型（1.5–2 周，P1）
- `is_internal` 内部备注（表 + API + 详情页切换 + 通知只发内部）
- 关注者/CC、回复分页、`attachments.reply_id`
- 工单关联（related/duplicate/blocks）与轻量合并
- 客户重开 + 自动关闭命令 + `closed_reason`
- 保存视图（我的待办/未指派紧急/即将超时）
- 批量改优先级/分类/标签

### Sprint 2 —— 服务管理与效率（2 周，P1）
- SLA 策略化：策略表 + 营业时间/节假日 + 解决时限 + 暂停
- 团队/队列 + 自动分配（轮询/负载）
- 宏 + 快捷回复（变量替换）
- 通知偏好（事件 × 通道）+ 邮件聚合
- 邮件入站（IMAP 轮询或邮件服务商 Inbound Webhook）+ 线程化

### Sprint 3 —— 渠道与分析（2–2.5 周，P1/P2）
- 公开门户：未登录提交、工单查询、公开 KB
- KB 升级：分类/搜索/反馈/工单转文章
- 报表中心：区间、SLA 合规率、账龄、FCR、CSAT 趋势、导出
- 客户的 360 视图 + 组织

### Sprint 4 —— 平台化（2–3 周，P2）
- 触发器引擎 + 审计事件流（before/after/IP）
- Webhook + API Token + OpenAPI + `/api/v1`
- 2FA + 自助找回密码 + 登录审计
- CI/CD、Larastan、Sentry、Docker、备份 runbook
- i18n + 时区 + A11y + 命令面板

### 暂不建议做（避免过度设计）
- 多租户 / 多品牌（除非确定做 SaaS）
- 电话/社交渠道、CMDB/资产、问题/变更管理（除非做 ITIL）
- 工时计费、原生 App、复杂审批流
- AI 功能（等数据量与话术库稳定后再做摘要/起草/分类）

---

## 9. 产品定位（已确认）

2026-10-27 与需求方确认：

1. **定位**：**对外售后服务平台**——服务的是"自己产品"的客户，不是内部员工。
2. **租户模型**：**单租户、自用**，不做 SaaS 多租户，不做多品牌、不做计费。
3. **邮件**：需要作为一等渠道（对外客户不会为了提一个问题专门注册账号）。

由此产生的硬约束，直接改写后面的优先级（见 §12）：

- **客户侧已有注册登录**（`client/*` 全部在 `auth` 之后），沿用即可，不引入免登录、邮箱验证码、工单号查询这套体系。
- **小团队（个位数客服）**：不做团队/队列/技能路由，不做客户分层，不做合同式 SLA。
- **不涉及资金**：没有退款、补偿、赠送时长，因此不需要审批流与财务留痕。
- **自用单产品**：不做多语言、多租户、多品牌、计费。

仍然待定（不阻塞开工）：

- 帮助中心是否公网可访问（决定要不要做 SEO 与内容运营）
- 客户主要从哪来（站内 / 邮件 / 微信群），决定通知渠道的取舍

---

## 10. 如果只做一件事（通用结论）

> 定位修正后的答案见 §11.5——对外售后场景下，优先级最高的不是内部备注，而是"业务上下文 + 自助入口"。

**加"内部备注"**。它是投入产出比最高的改动：一张字段（`is_internal`）+ 一个开关 + 通知过滤，就能让客服从"各自对着客户说话"变成"团队协作处理一张工单"，并顺势打开关注者、合并、质检、宏等一整套协作能力。

---

## 11. 定位锁定后的第一版修正（已被 §12 取代，保留作对标参考）

前面 §4 的 15 个维度是"对标商业产品"的横向清单，适用但不分先后。锁定"对外售后"后，权重必须重排：**客户体验侧 > 客服协作侧 > 内部管理侧**，ITIL 那一整块（审批、资产、问题/变更、CMDB）直接不做。

### 11.1 原报告低估的 6 项能力（对外售后专属）

这 6 项在通用清单里没有位置，但对"自有产品售后"是刚需：

| # | 能力 | 为什么是刚需 | 现在的状态 |
|---|---|---|---|
| A | **客户业务上下文**（Customer 360 + 业务系统只读集成） | 客服第一句话不该是"请提供订单号"。账号、套餐、到期时间、版本、设备、近 30 天用量、历史工单应在一屏内 | 无。`users` 只有账号密码角色，详情页无客户信息 |
| B | **免登录 / 轻身份入口** | 对外客户不愿注册；邮件回复本身就是身份。需要"邮箱 + 验证码"建单、"工单号 + 邮箱"查询 | 无。所有 `client/*` 路由都在 `auth` 之后 |
| C | **产品内触点**（帮助中心 widget / 反馈入口 / 已知问题横幅） | 客户卡住的位置就在产品里，把他导到外部站点会流失一半 | 无。只有 `/m/*` 移动端 SPA |
| D | **公开帮助中心 + 已知问题公告** | 直接降工单量；产品故障时批量告知受影响用户，而不是逐单回复 | `faqs` 登录后可见，无分类/搜索/公告 |
| E | **客户分层**（免费 / 付费 / VIP / 企业） | 决定 SLA、优先级、是否插队、谁接待；B2C 售后没有分层就会"大客户和小白用户抢同一个队列" | 无。所有工单同权 |
| F | **客服 ↔ 研发闭环** | 售后 60% 的工单最终是 bug 或需求。需要"转研发单（Jira/GitHub/Linear）+ 研发状态回传 + 批量通知受影响客户" | 无。工单止于客服 |

补充两条容易被忽略的：

- **涉及资金的操作用审批与留痕**：退款、补偿、赠送时长、重置账号，必须记录"谁批的、批了多少、对应哪张单"，否则财务对不上账。
- **故障期间的批量沟通（incident communication）**：一次线上故障可能带来 200 张相同工单。需要"关联到同一事件 + 一键批量回复 + 状态页/横幅"，否则客服被淹没。这是对外售后与内部 IT 最大的运营差异。

### 11.2 优先级重排（对外售后版）

**P0 —— 没有它就不能对外营业（约 3–4 周）**

1. §7 的 7 个止血项（越权、token、SLA 判定、日志外泄、工单号竞态、软删除、限流）
2. **免登录建单 + 工单查询**（邮箱验证码；`ticket_no + email` 查询）
3. **邮件入站/出站闭环**（`support@` 收信建单、`Reply-To` 线程化、品牌化模板）
4. **客户 360 卡片**（先做站内已有的：历史工单、评分、标签、备注；业务字段用可配置 `custom_fields` + 手动/半自动同步过渡）
5. **公开帮助中心 v1**（分类 + 搜索 + 文章页 + 提交前推荐）

**P1 —— 决定服务效率与口碑（约 4–6 周）**

6. 内部备注 + 关注者 + 关联/合并（客服与研发协作）
7. 客户分层 + SLA 策略化（营业时间、解决时限、暂停、升级）
8. 分类对应产品模块 + 按分类的表单分支（故障/账单/账号/需求）
9. **客服 → 研发**：转单 + 状态回传 + 受影响客户批量通知
10. CSAT 自动发送（关闭后 24h 邮件调查）+ 低分自动回访
11. 宏 + 快捷回复 + 自动分配
12. **已知问题 / 公告（incident banner）**

**P2 —— 规模化与体验（约 6–8 周）**

13. 产品内嵌 widget（复用现有 WebSocket 与建单 API）
14. 保存视图、全文搜索、报表中心（SLA 合规率、账龄、FCR、CSAT 趋势、渠道分布）
15. 触发器引擎、通知偏好、Webhook / API Token
16. 2FA、自助找回密码、登录审计、附件扫描
17. 多语言 / 时区、PWA、命令面板、A11y
18. CI/CD、Larastan、Sentry、对象存储、Redis

**明确不做（省下的时间投到上面）**

- 多租户、多品牌、计费、白标
- 审批流（除资金类操作外）、CMDB、资产、问题管理、变更管理、工时计费
- 电话/语音、原生 App、复杂 SLA 合同管理
- 过早做 AI（等话术库与工单量起来后，先做"摘要 + 分类建议"）

### 11.3 数据模型的调整（相对 §5）

- **保留**：`ticket_replies.is_internal`、`ticket_links`、`ticket_watchers`、`saved_views`、`macros`、`triggers`、`sla_policies` + `business_hours`、`kb_articles`、`csat_surveys`、`inbound_emails`、`webhooks`、`notification_preferences`、`ticket_events`
- **简化**：`organizations` 降级为可选（B2C 为主时只留 `users.company` 文本字段）；`teams` 保留但只需 2–3 个（售前/售后/技术）
- **新增**：
  - `customers` 扩展（或 `users` 加列）：`tier`（free/paid/vip/enterprise）、`plan_name`、`plan_expires_at`、`product_version`、`external_id`（业务系统主键）、`locale`、`timezone`
  - `ticket_categories` 加 `product_area`（对应产品模块）与 `form_schema`（JSON，按分类渲染不同字段）
  - `incidents`（id, title, status, started_at, resolved_at, affected_plan, public_message）
  - `ticket_incident`（工单挂到事件，用于批量回复）
  - `refunds` / `compensations`（ticket_id, amount, currency, approved_by, approved_at, reason）——资金动作留痕
  - `dev_links`（ticket_id, provider, external_key, external_url, status）——研发单关联
  - `kb_feedback`（article_id, helpful, reason）

### 11.4 90 天建议节奏

| 周 | 目标 | 交付 |
|---|---|---|
| W1 | 止血 | §7 七项修复 + 上线前安全检查 |
| W2–3 | 开门营业 | 免登录建单/查询 + 邮件出入站 + 品牌化通知模板 |
| W3–4 | 降量 | 公开帮助中心 v1 + 提交前推荐 + 已知问题页 |
| W5–6 | 看见客户 | 客户 360（历史工单/评分/标签/业务字段）+ 客户分层 |
| W6–7 | 团队协作 | 内部备注 + 关注者 + 关联/合并 |
| W8–9 | 服务承诺 | SLA 策略化（营业时间/解决时限/暂停/升级）+ CSAT 自动调查 |
| W10–11 | 研发闭环 | 转研发单 + 状态回传 + 事件批量通知 |
| W12 | 效率 | 宏 + 快捷回复 + 自动分配 + 报表中心 v1 |

### 11.5 修正后的"如果只做一件事"

**做"客户 360 + 业务字段"**。对外售后的核心成本不在"回复"，而在"上下文获取"——客服每张单平均要花 2–4 轮对话去确认"你是谁、你买了什么、什么版本、什么环境"。把这屏信息补上，响应时长和客户体验会同时改善，而且它不依赖任何新渠道，是纯粹的内部改动。

如果只能再改一屏，选**公开帮助中心**——它是唯一能"减少工单总量"的功能，其余所有功能都只是"更快地处理工单"。

---

## 12. 最终范围（简化版：小团队自用 · 对外售后）

§11 是按"商业售后平台"写的：13 张新表、SLA 策略引擎、客户分层、事件管理、研发集成、免登录体系。对着"自己注册登录、不做资金操作、不是大公司"这个前提，**这些大部分是过度设计**。真正该做的是：**把现有闭环修顺，再补 4 个小功能**。

### 12.1 只做 8 件事（总计 3–4 周，单人可完成）

| # | 事项 | 工作量 | 为什么值得做 |
|---|---|---|---|
| 1 | **止血修复**：§7 的 7 项（越权、token、SLA 判定、日志外泄、工单号竞态、软删除、`per_page` 上限 + 限流） | 1–2 天 | 越权是"客户能读别人的工单回复"，这是信任问题，必须先修 |
| 2 | **内部备注**：`ticket_replies.is_internal` + 详情页切换 + 通知只发内部 | 0.5–1 天 | 客服与研发的讨论留在单内，不再散落在微信；客户看不到 |
| 3 | **快捷回复模板**：一张表 + 下拉插入 | 1 天 | 售后 70% 是重复问题，回复从 3 分钟降到 20 秒 |
| 4 | **邮件深链回跳 + 通知开关**：`?redirect=` 登录后回到工单；用户级"关掉邮件通知" | 0.5 天 | 现在点邮件会落到首页，等于白通知 |
| 5 | **自动关闭 + 客户重开**：`pending_resolved` 满 7 天自动关闭；关闭后 N 天内客户可重开 | 1 天 | 否则列表里永远挂着一堆"等确认"的死单 |
| 6 | **回复分页 + 列表记住筛选**（前端 `localStorage` 即可，不建表） | 1 天 | 长工单不卡；回列表不丢状态 |
| 7 | **FAQ 升级为帮助中心**：分类、搜索、公开访问、有用/没用 | 2–3 天 | 唯一能"减少工单总量"的功能，而不是更快处理 |
| 8 | **报表加时间区间 + SLA 合规率** | 1–2 天 | 现在固定 7 天、只有计数，看不出服务质量 |

### 12.2 数据模型只动 4 处（不新建那 13 张表）

```
ticket_replies
  + is_internal  boolean default false  index

canned_responses (id, title, body, category_id NULL, created_by, timestamps)

tickets
  + closed_reason   string(30) NULL     -- solved|duplicate|invalid|customer_silent
  + reopen_count    smallint default 0
  + last_activity_at timestamp NULL index
  + deleted_at      softDeletes

faqs                                -- 直接扩，不建 kb_articles
  + category_id, slug, is_public, views, helpful_yes, helpful_no
```

**明确不需要**：`teams`、`organizations`、`sla_policies`、`business_hours`、`triggers`、`macros`、`webhooks`、`incidents`、`dev_links`、`refunds`、`csat_surveys`。

### 12.3 逐项判断（为什么砍）

- **免登录 / 邮箱验证码**：砍。客户已有账号体系，`client/*` 本来就在登录后，不需要再造一套身份。
- **客户 360 / 业务字段集成**：降级为"客户详情页显示历史工单 + 评分 + 备注"，3 个字段手工维护即可，不接业务系统。
- **客户分层**：砍。小团队客户量不大，靠优先级字段足够。
- **SLA 策略引擎 / 营业时间 / 暂停 / 升级链**：砍。修好 `isOverdue()` 的 bug、保留现有 4 档固定时长就够用。
- **事件管理 / 批量通知受影响客户**：砍。故障量小时在公告里说一句即可。
- **转研发单 / Jira 集成**：砍。用内部备注 @ 研发 + 标签 `bug` 足够。
- **审批流 / 资金操作留痕**：砍。不涉及退款补偿。
- **多语言 / 多租户 / 计费 / 白标**：砍。
- **Webhook / API Token / OpenAPI**：砍。没有第三方要接。
- **2FA / SSO**：可延后。单团队自用，登录限流 + 强密码已经够。

### 12.4 先不做、但保留为"以后可能"的 4 件

1. **全文搜索**：工单量 < 5000 时 `LIKE` 加索引够用；到 1 万再换 ngram。
2. **邮件入站（IMAP 收信建单）**：现有"发通知 + 点链接登录回复"够用；真要做，一天能接。
3. **触发器 / 自动分配**：小团队手动指派反而更快，人少时自动化收益为负。
4. **产品内嵌 widget**：等有客户抱怨"找不到提工单入口"再说。

### 12.5 如果只做一件事

**内部备注**。半天工作量，解决"客服和研发在微信里讨论一张单，结论最后丢了"的问题——这是小团队最真实、最高频的损耗。第二件是**快捷回复模板**，一天上线。

---

*附：本报告基于当前仓库代码静态阅读 + `php artisan test`（56 passed / 234 assertions）与路由/迁移/控制器/前端视图核对；未做运行时压测与渗透测试。*

---

## 13. 实施记录（已完成）

> 本节记录 §12 简化版方案落地情况。时间：2026-10-27；基线 `2ae4876`。

### 13.1 已交付（对应 §12 八件事）

| # | 事项 | 状态 | 主要落点 |
|---|---|---|---|
| 1 | 止血 7 项 | ✅ | 见下方 13.2 |
| 2 | 内部备注 | ✅ | `ticket_replies.is_internal` + 回复框开关/虚线气泡 |
| 3 | 快捷回复模板 | ✅ | `canned_responses` 表 + 回复框下拉与“管理”弹窗 |
| 4 | 邮件深链登录后回跳 | ✅ | 路由守卫带 `?redirect=`，客户端/后台登录页均支持 |
| 5 | 待确认自动关闭 + 客户重开 | ✅ | `tickets:auto-close`（每日 02:00）+ `POST /tickets/{id}/reopen`（14 天内、最多 3 次） |
| 6 | 列表记住筛选 | ✅ | `localStorage` 键 `ticket-list-filters` |
| 7 | FAQ 升级帮助中心 | ✅ | 分类、搜索、公开开关、阅读数、有帮助/没帮助 |
| 8 | 报表时间区间 + SLA 合规率 | ✅ | `/api/stats?days=7\|30\|90`，返回 `range.sla_rate`（首次响应达标率） |
| — | 回复分页 | ✅ | 详情只返回最新 30 条 + `replies_count`，更早的用 `GET /tickets/{id}/replies?before_id=&per_page=` 游标翻页；会话区顶部“加载更早的回复” |

### 13.2 止血清单落地

| 问题 | 修复 |
|---|---|
| 回复列表越权（任意登录用户可读他人工单回复） | `ReplyController@index` 先 `authorize('view')`，并按角色过滤内部备注 |
| Sanctum token 永不过期 | `config/sanctum.php` 默认 30 天，可用 `SANCTUM_EXPIRATION` 覆盖 |
| 已响应工单永远显示超时 | `Ticket::isOverdue()` 与 `overdue` 筛选都加 `first_response_at IS NULL` |
| 操作日志对客户可见 | `show()` 仅 staff 加载 `logs`，前端“操作记录”卡片加 `v-if="auth.isStaff"` |
| 工单号并发撞号 | `nextNo()` 改数值 `MAX(SUBSTRING(...))` + `withTrashed()`；`store()` 撞唯一索引自动重试 |
| 硬删除丢数据 | `Ticket` 启用 `SoftDeletes`，旧测试改为 `assertSoftDeleted` |
| `per_page` 无上限 | 夹到 1–100 |
| 登录/注册无全局限流 | 注册 5/分、登录 10/分、验证码 30/分、登录后 240/分 |

### 13.3 顺带补齐

- 改优先级后按 `created_at` 重算 `sla_due_at`；状态回退清空 `resolved_at/closed_at/closed_reason`。
- 批量指派补发通知；批量关闭写 `closed_reason=manual`。
- 客服绩效从「每坐席 N 次查询」改为两次聚合查询。
- 邮件通知的工单链接经 `/tickets/{id}` 统一分流到移动端或后台。
- **邮箱改为纯选填**：注册表单不再要求邮箱；移动端「我的 → 账号设置」可随时绑定/解绑；没有邮箱的用户跳过邮件通道（只发站内信与实时推送），避免队列任务发往空地址失败。
- **系统设置页（v2.18）**：`settings` 表 + `/panel/settings`，可配站点名称、开放注册、邮件通知开关、附件上限、四档 SLA、待确认自动关闭天数、重开窗口与次数；SLA/自动关闭/重开/附件/注册/邮件均改为读设置，不再写死在代码里。
- **角色与权限（v2.19）**：`roles` 表存 15 项细粒度权限（工单/内容/数据/系统四组），内置「普通用户 / 客服 / 管理员」三个角色，管理员可新建自定义角色（如「售后客服」「数据观察员」）；侧边栏菜单、前端路由、后端接口三层都按权限放行，权限改动即时生效、无需重新登录。
- **角色数据范围（v2.20）**：每个角色可选「全部工单 / 仅我受理的 / 仅我提交的」，工单列表、详情、回复、CSV 导出都按范围过滤；「全部工单」需同时拥有 `ticket.view_all` 权限，否则自动降级为「仅我受理的」。统计看板仍是团队维度，不随数据范围收窄。
- **实时连接地址修复（v2.21.1）**：`GATEWAY_PUBLIC_WS_URL` 留空时自动按当前站点派生（https → `wss://host/ws`），HTTPS 页面遇到 `ws://` 会自动升级为 `wss://` 并告警；重连不再丢订阅者、改为 3s 起退避；新增 `docs/REALTIME.md`（同源反代 / 网关直开 wss 两种部署方式与排查清单）。
- **用户详情抽屉（v2.21）**：用户管理点姓名打开抽屉，显示基本信息、提交/受理工单数与进行中数量、最近提交/最近受理的 5 条工单（可直接跳转详情）；接口 `GET /api/users/{user}`，受 `user.manage` 权限控制。
- **用户管理升级（v2.19）**：搜索（姓名/账号/邮箱）+ 角色筛选 + 启用状态筛选 + 分页 + 最后登录时间；新增「编辑账号」（改姓名/邮箱/角色/启停用）与「停用账号」——停用即踢掉全部登录、再登录返回 403；保留「重置密码」。

### 13.4 验证

- `php artisan test`：**105 passed / 465 assertions**（新增 `EngagementTest` 14 条、`FaqTest` 4 条、`SettingsTest` 7 条、`RoleTest` 7 条、`UserManagementTest` 8 条、`TicketScopeTest` 6 条）。
- `npm run build`：通过（仅剩既有的 chunk 体积告警）。
- 新增迁移 8 个，`php artisan migrate` 已在开发库执行；生产部署记得跑迁移并确认 `schedule:run` 已配 cron。
- 权限约定：接口用 `permission:key` 中间件，前端用 `auth.can('key')`；新增敏感接口时两处都要加。

### 13.5 仍建议后续

1. 帮助中心的“未命中搜索 → 一键提工单”埋点，用于反推该写哪些 FAQ。
2. 邮件入站（IMAP）与触发器/自动分配，等工单量起来再说。
3. 回复全文搜索（工单内搜索历史结论），工单量大后再做。

### 13.6 尚未做（按 2026-10-27 代码现状盘点）

**A. 已知缺陷/埋雷**

| 项 | 现状 | 影响 |
|---|---|---|
| 用户外键级联 | `tickets/replies/attachments/ticket_logs.user_id` 仍是 `cascadeOnDelete` | 目前没有删用户接口；一旦加「删除账号」会连带物理删除其全部工单 |
| 工单回收站 | `tickets` 已软删除，但没有 restore 接口/页面 | 误删只能进库改 `deleted_at` |
| 日志接口分页 | `TicketLogController@index` 的 `per_page` 未夹上限 | 可被 `per_page=100000` 拉全表 |
| 通知列表 | 只取最近 20 条，无分页/加载更多 | 通知多了看不到更早的 |

**B. 功能缺口**

| 项 | 说明 |
|---|---|
| 关注者/参与者 | 只有提交人 + 受理人收通知；主管/协作客服无法长期跟进，@提及只在单条回复里 |
| 合并/关联工单 | 重复报障只能人工沟通 |
| 解决时限 SLA | 只有首次响应；`pending_resolved` 不停表，无营业时间/暂停 |
| 找回密码 | 完全没有；有邮箱也没入口，无邮箱只能管理员重置 |
| 通知偏好 | 无用户级「邮件/站内信」开关；只有系统级 `notify_mail` 总开关；邮件模板写死在通知类里 |
| 公开帮助中心 | `/api/faqs` 本身公开，但前端 `/m/faqs` 需登录；没有匿名可看的帮助中心/公开门户 |
| 搜索范围 | 只搜标题与编号，不搜工单正文、回复内容 |
| 附件治理 | 无单工单数量上限、无孤儿文件清理、删除回复不删附件 |
| 统计 | 只有团队维度，无个人绩效、无导出、无响应时长分布 |
| 权限审计 | 角色/用户权限变更没有留痕（`ticket_logs` 只记工单动作） |

**C. 明确搁置（此前决定不做）**

邮件入站（IMAP）、触发器/自动分配、Webhook/API Token、2FA、多语言、多租户、产品内 widget；
前端无单元测试、无 CI（无 `.github`）、无 Pint/PHPStan 配置；
WebSocket 仍是直连 `wss://host:1238`（反代方案因 ServBay vhost 自动生成而暂缓，token 仍在 URL）。
