import http from './api';

/**
 * 即时消息连接（GatewayWorker WebSocket）
 *
 * 地址来源优先级：
 * 1. blade 注入的 window.GATEWAY_WS_URL（.env 的 GATEWAY_PUBLIC_WS_URL）
 * 2. 未配置时自动用「当前站点 + /ws」：https 页面 → wss://host/ws，http 页面 → ws://host/ws
 *    （需要在站点 nginx 把 /ws 反代到网关，见 docs/REALTIME.md）
 */
const configuredUrl = window.GATEWAY_WS_URL || '';

let ws = null;
let connecting = null;
let reconnectTimer = null;
let stopped = false; // 登出后彻底停掉，不再自动重连
let reconnectDelay = 1000;
let handlers = [];

/** 计算最终连接的地址（同时处理 https 页面连 ws:// 的混合内容问题） */
export function realtimeUrl() {
    if (configuredUrl) {
        if (window.location.protocol === 'https:' && configuredUrl.startsWith('ws://')) {
            console.warn('[realtime] 页面是 HTTPS，ws:// 会被浏览器拦截，已自动升级为 wss://；建议把 GATEWAY_PUBLIC_WS_URL 配成 wss://');
            return `wss://${configuredUrl.slice('ws://'.length)}`;
        }
        return configuredUrl;
    }

    const scheme = window.location.protocol === 'https:' ? 'wss' : 'ws';

    return `${scheme}://${window.location.host}/ws`;
}

/**
 * 换取一次性连接票据（60 秒有效、只能用一次）
 * 长期 token 不再拼进 WS URL，避免落进 nginx 日志 / 浏览器历史。
 */
async function fetchTicket() {
    const token = localStorage.getItem('ticket_token');
    if (! token) return null;

    try {
        const res = await fetch('/api/ws-ticket', {
            method: 'POST',
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (! res.ok) return null;
        const data = await res.json();

        return data.ticket || null;
    } catch {
        return null;
    }
}

/**
 * 即时消息连接（GatewayWorker WebSocket）
 * onMessage: (payload) => void  payload 为通知内容 {event, message, ticket_id, ...}
 */
export async function connectRealtime(onMessage) {
    // 带 handler 的调用来自页面主动挂载（登录后），解除 stopped；退避重连不传 handler，保持停止状态
    if (onMessage) stopped = false;
    if (stopped) return;
    if (onMessage && ! handlers.includes(onMessage)) handlers.push(onMessage);
    if (ws || connecting) return;

    connecting = fetchTicket();
    const ticket = await connecting;
    connecting = null;

    // 没有票据（未登录 / 接口失败）就退避重试，别让调用方空转
    if (! ticket || ws) {
        if (! ws && localStorage.getItem('ticket_token')) scheduleReconnect();
        return;
    }

    const url = realtimeUrl();
    const startedAt = performance.now();
    let authed = false;
    ws = new WebSocket(`${url}?ticket=${encodeURIComponent(ticket)}`);

    ws.onopen = () => {
        // 注意：101 只代表 TCP/TLS 通了，网关可能马上回 auth_failed 再断开，
        // 所以退避计数要等收到 connected 才清零，否则会在 1s 间隔里无限重连。
        const cost = Math.round(performance.now() - startedAt);
        if (cost > 800) console.info(`[realtime] WebSocket 连接耗时 ${cost}ms`);
    };

    ws.onmessage = (e) => {
        let payload;
        try {
            payload = JSON.parse(e.data);
        } catch {
            return; // pong 等纯文本心跳
        }
        if (payload.type === 'connected') {
            authed = true;
            reconnectDelay = 1000;
            return;
        }
        // 网关每 15s 发一次应用层心跳，浏览器不会自动回，必须自己回 pong，
        // 否则 3 次不应答（约 45s）就被网关断开，表现就是「每分钟掉线重连一次」
        if (payload.type === 'ping') {
            if (ws && ws.readyState === WebSocket.OPEN) ws.send('pong');
            return;
        }
        if (payload.type === 'auth_failed') {
            console.warn('[realtime] 网关鉴权失败：票据无效/过期，或网关未重启仍不认 ticket');
            return;
        }
        // 其余派发给订阅者（notification / reply / ...）
        if (payload.type && payload.type !== 'ping') {
            handlers.forEach((fn) => fn(payload));
        }
    };

    ws.onerror = () => {
        // 常见原因：网关未启动、地址不对、https 页面连了 ws://、反代没配 Upgrade 头
        console.warn(`[realtime] WebSocket 连接异常：${url}`);
    };

    ws.onclose = () => {
        ws = null;
        // 没通过鉴权就断开：不重置退避，避免网关拒连时形成 1 秒一次的循环
        scheduleReconnect(authed ? 1000 : reconnectDelay);
    };
}

/** 退避重连：1s → 2s → 4s → 8s → 15s → 最大 30s */
function scheduleReconnect(delay = reconnectDelay) {
    if (stopped) return;
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => {
        reconnectDelay = Math.min(Math.max(delay, 1000) * 2, 30000);
        if (localStorage.getItem('ticket_token')) {
            connectRealtime();
        }
    }, delay);
}

export async function fetchNotifications(page = 1) {
    const { data } = await http.get('/notifications', { params: { page } });

    return data;
}

/** 立刻重连（网络恢复 / 页面重新可见时调用，不等退避计时器） */
export function reconnectNow() {
    if (ws || connecting || ! localStorage.getItem('ticket_token')) return;
    clearTimeout(reconnectTimer);
    reconnectDelay = 1000;
    connectRealtime();
}

export function disconnectRealtime() {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
    stopped = true;
    handlers.length = 0;
    if (ws) {
        try { ws.close(); } catch { /* noop */ }
        ws = null;
    }
    connecting = false;
}

if (typeof window !== 'undefined') {
    window.addEventListener('online', reconnectNow);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') reconnectNow();
    });
}
