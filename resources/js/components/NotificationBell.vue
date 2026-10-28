<template>
    <el-badge :value="unread" :hidden="unread === 0" :max="99" class="bell">
        <el-icon :size="20" @click="open = !open"><Bell /></el-icon>
    </el-badge>
    <el-popover
        v-model:visible="open"
        :width="width"
        trigger="click"
        placement="bottom-end"
        :popper-style="{ padding: '10px 12px' }"
        @show="load"
    >
        <template #reference><span /></template>
        <div v-if="items.length === 0" class="nf-empty">
            <el-icon :size="26"><Bell /></el-icon>
            <p>暂无通知</p>
            <span>有新的回复或状态变化会在这里提醒你</span>
        </div>
        <div v-else>
            <div class="nf-head">
                <span>通知</span>
                <el-button v-if="unread > 0" link type="primary" @click="markAll">全部已读</el-button>
            </div>
            <el-scrollbar :max-height="scrollHeight">
                <div
                    v-for="n in items"
                    :key="n.id"
                    class="nf-item"
                    :class="{ unread: !n.read }"
                    @click="goTicket(n)"
                >
                    <div class="nf-msg">{{ n.message }}</div>
                    <div class="nf-time">{{ /^\d{4}/.test(n.created_at) ? timeAgo(n.created_at) : n.created_at }}</div>
                </div>
            </el-scrollbar>
            <div v-if="hasMore" class="nf-more">
                <el-button link type="primary" :loading="loadingMore" @click="load(true)">加载更多</el-button>
            </div>
        </div>
    </el-popover>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Bell } from '@element-plus/icons-vue';
import { ElNotification } from 'element-plus';
import http from '../api';
import { connectRealtime, fetchNotifications } from '../realtime';
import { timeAgo } from '../time';
import { ticketPath } from '../paths';
import { playNotifySound } from '../utils/sound';

// 手机上弹层要跟着视口收窄，否则 360px 固定宽会超出屏幕
const viewport = ref(window.innerWidth);
const width = computed(() => Math.min(360, viewport.value - 32));
const scrollHeight = computed(() => (viewport.value < 768 ? '50vh' : '320px'));
const onResize = () => { viewport.value = window.innerWidth; };

const unread = ref(0);
const items = ref([]);
const open = ref(false);
const route = useRoute();
const router = useRouter();
let timer = null;
const page = ref(1);
const hasMore = ref(false);
const loadingMore = ref(false);

async function load(more = false) {
    loadingMore.value = more;
    try {
        const data = await fetchNotifications(more ? page.value + 1 : 1);
        if (more) {
            page.value += 1;
            items.value.push(...data.data);
        } else {
            page.value = 1;
            items.value = data.data;
        }
        unread.value = data.unread;
        hasMore.value = !! data.has_more;
    } finally {
        loadingMore.value = false;
    }
}

// 即时消息：网关推送的通知实时刷角标 + 弹 toast（正在查看的工单不弹，由聊天区实时追加）
connectRealtime((payload) => {
    if (payload.type === 'notification') {
        unread.value += 1;
        items.value.unshift({ ...payload, read: false, created_at: '刚刚' });
        const viewing = String(route.params.id || '') === String(payload.ticket_id);
        playNotifySound();
        if (! viewing) {
            ElNotification({
                title: payload.ticket_no || '新通知',
                message: payload.message,
                type: payload.event === 'sla_breach' ? 'error' : 'info',
                duration: 5000,
                onClick: () => router.push(ticketPath(payload.ticket_id)),
            });
        }
    }
});

async function goTicket(n) {
    if (!n.read) {
        await http.post('/notifications/read', { id: n.id });
    }
    open.value = false;
    await load();
    router.push(ticketPath(n.ticket_id));
}

async function markAll() {
    await http.post('/notifications/read', {});
    await load();
}

onMounted(() => {
    load();
    timer = setInterval(load, 30000);
    window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    window.removeEventListener('resize', onResize);
});

defineExpose({ load });
</script>

<style scoped>
.bell {
    cursor: pointer;
    display: flex;
    align-items: center;
    color: var(--el-text-color-regular);
}
.bell :deep(.el-badge__content) {
    top: 6px;
    right: 10px;
}
.nf-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    font-size: 13px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--el-border-color-lighter);
}
.nf-empty {
    text-align: center;
    padding: 22px 8px;
    color: var(--el-text-color-secondary);
}
.nf-empty p {
    margin: 8px 0 2px;
    font-size: 14px;
    color: var(--el-text-color-regular);
}
.nf-empty span {
    font-size: 12px;
}
.nf-item {
    padding: 10px 4px;
    border-bottom: 1px dashed var(--el-border-color-lighter);
    cursor: pointer;
    border-radius: 8px;
}
.nf-item:hover {
    background: var(--el-fill-color-light);
}
.nf-item.unread .nf-msg {
    font-weight: 600;
}
.nf-msg {
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-primary);
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
}
.nf-time {
    font-size: 12px;
    color: var(--el-text-color-placeholder);
    margin-top: 3px;
}
.nf-more {
    text-align: center;
    padding-top: 8px;
}
</style>
