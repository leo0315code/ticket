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
let reconnectTimer = null;
let reconnectDelay = 3000;
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
 * 即时消息连接（GatewayWorker WebSocket）
 * onMessage: (payload) => void  payload 为通知内容 {event, message, ticket_id, ...}
 */
export function connectRealtime(onMessage) {
    if (! handlers.includes(onMessage)) handlers.push(onMessage);
    if (ws) return;

    const token = localStorage.getItem('ticket_token');
    if (! token) return;

    const url = realtimeUrl();
    ws = new WebSocket(`${url}?token=${encodeURIComponent(token)}`);

    ws.onopen = () => {
        reconnectDelay = 3000;
    };

    ws.onmessage = (e) => {
        let payload;
        try {
            payload = JSON.parse(e.data);
        } catch {
            return; // pong 等纯文本心跳
        }
        // connected/ping 为连接层消息，其余派发给订阅者（notification / reply / ...）
        if (payload.type && payload.type !== 'connected' && payload.type !== 'ping') {
            handlers.forEach((fn) => fn(payload));
        }
    };

    ws.onerror = () => {
        // 常见原因：网关未启动、地址不对、https 页面连了 ws://、反代没配 Upgrade 头
        console.warn(`[realtime] WebSocket 连接异常：${url}`);
    };

    ws.onclose = () => {
        ws = null;
        // 断线重连（3s 起，最长 15s）；token 失效时由 api 拦截器统一跳登录
        clearTimeout(reconnectTimer);
        reconnectTimer = setTimeout(() => {
            reconnectDelay = Math.min(reconnectDelay * 1.5, 15000);
            if (localStorage.getItem('ticket_token')) {
                connectRealtime(onMessage);
            }
        }, reconnectDelay);
    };
}

export async function fetchNotifications() {
    const { data } = await http.get('/notifications');

    return data;
}
