import { createApp } from 'vue';
import { createPinia } from 'pinia';
import ElementPlus from 'element-plus';
import zhCn from 'element-plus/es/locale/lang/zh-cn';
import 'element-plus/dist/index.css';
import 'element-plus/theme-chalk/dark/css-vars.css';
import App from './App.vue';
import router from './router';
import { initTheme } from './theme';
import { connectRealtime } from './realtime';
import { loadMeta } from './meta';
import './app.css';

initTheme();

const app = createApp(App);

// 开发环境：给 "prop title 传 null" 这类告警补组件调用栈，便于定位来源（生产构建自动剥离）
if (import.meta.env.DEV) {
    const originalWarn = console.warn;
    console.warn = (...args) => {
        if (String(args[0] || '').includes('type check failed for prop "title"')) {
            originalWarn.apply(console, args);
            console.trace('[title=null 告警来源堆栈]');
            return;
        }
        originalWarn.apply(console, args);
    };
}
app.use(createPinia());
app.use(router);
app.use(ElementPlus, { locale: zhCn });

// 站点元信息（名称/登录方式开关）先拿到再挂载，避免标题、入口闪烁
await loadMeta();
app.mount('#app');

// 登录态下尽早建立即时消息连接，不等布局组件挂载（连接本身是全局共享的）
if (localStorage.getItem('ticket_token')) {
    connectRealtime();
}
