<template>
    <el-badge :value="unread" :hidden="unread === 0" :max="99" class="bell">
        <el-icon :size="20" @click="open = !open"><Bell /></el-icon>
    </el-badge>
    <el-popover v-model:visible="open" width="360" trigger="click" @show="load">
        <template #reference><span /></template>
        <div v-if="items.length === 0" style="text-align: center; color: #909399; padding: 16px 0">暂无通知</div>
        <div v-else>
            <div class="nf-head">
                <span>通知</span>
                <el-button v-if="unread > 0" link type="primary" @click="markAll">全部已读</el-button>
            </div>
            <el-scrollbar max-height="320px">
                <div
                    v-for="n in items"
                    :key="n.id"
                    class="nf-item"
                    :class="{ unread: !n.read }"
                    @click="goTicket(n)"
                >
                    <div class="nf-msg">{{ n.message }}</div>
                    <div class="nf-time">{{ n.created_at }}</div>
                </div>
            </el-scrollbar>
        </div>
    </el-popover>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Bell } from '@element-plus/icons-vue';
import { ElNotification } from 'element-plus';
import http from '../api';
import { connectRealtime, fetchNotifications } from '../realtime';
import { ticketPath } from '../paths';
import { playNotifySound } from '../utils/sound';

const unread = ref(0);
const items = ref([]);
const open = ref(false);
const route = useRoute();
const router = useRouter();
let timer = null;

async function load() {
    const data = await fetchNotifications();
    unread.value = data.unread;
    items.value = data.data;
}

// 即时消息：网关推送的通知实时刷角标 + 弹 toast（正在查看的工单不弹，由聊天区实时追加）
connectRealtime((payload) => {
    if (payload.type === 'notification') {
        unread.value += 1;
        items.value.unshift({ ...payload, read: false, created_at: '刚刚' });
        const viewing = route.path === `/tickets/${payload.ticket_id}`;
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
});
onBeforeUnmount(() => clearInterval(timer));

defineExpose({ load });
</script>

<style scoped>
.bell {
    cursor: pointer;
    margin-right: 24px;
    display: flex;
    align-items: center;
}
.nf-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
    padding-bottom: 6px;
    border-bottom: 1px solid #ebeef5;
}
.nf-item {
    padding: 10px 4px;
    border-bottom: 1px dashed #ebeef5;
    cursor: pointer;
}
.nf-item:hover {
    background: #f5f7fa;
}
.nf-item.unread .nf-msg {
    font-weight: 600;
}
.nf-msg {
    font-size: 13px;
    line-height: 1.5;
    color: #303133;
}
.nf-time {
    font-size: 12px;
    color: #909399;
    margin-top: 2px;
}
</style>
