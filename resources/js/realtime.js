import { ElNotification } from 'element-plus';
import http from './api';

// 地址由 blade 注入（见 app.blade.php），反代部署时配置 GATEWAY_PUBLIC_WS_URL
const WS_URL = window.GATEWAY_WS_URL || 'ws://127.0.0.1:1238';

let ws = null;
let reconnectTimer = null;
let handlers = [];

/**
 * 即时消息连接（GatewayWorker WebSocket）
 * onMessage: (payload) => void  payload 为通知内容 {event, message, ticket_id, ...}
 */
export function connectRealtime(onMessage) {
    handlers.push(onMessage);
    if (ws) return;

    const token = localStorage.getItem('ticket_token');
    if (! token) return;

    ws = new WebSocket(`${WS_URL}?token=${encodeURIComponent(token)}`);

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

    ws.onclose = () => {
        ws = null;
        // 断线重连（5s），token 失效时由 api 拦截器统一跳登录
        clearTimeout(reconnectTimer);
        reconnectTimer = setTimeout(() => {
            if (localStorage.getItem('ticket_token')) {
                handlers = [];
                connectRealtime(onMessage);
            }
        }, 5000);
    };
}

export async function fetchNotifications() {
    const { data } = await http.get('/notifications');

    return data;
}
