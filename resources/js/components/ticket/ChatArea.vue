<template>
    <div class="chat">
        <template v-for="item in items" :key="item.key">
        <div v-if="item.type === 'divider'" class="chat-divider">
            <span>{{ item.label }}</span>
        </div>
        <div
            v-else
            :data-id="item.r.id"
            class="chat-row"
            :class="{ mine: item.r.user.id === auth.user?.id, internal: item.r.is_internal }"
        >
            <el-avatar :size="34" :style="{ background: avatarColor(item.r.user.name) }">{{ item.r.user.name?.[0] }}</el-avatar>
            <div class="chat-main">
                <div class="chat-meta">
                    <b>{{ item.r.user.name }}</b>
                    <el-tag v-if="item.r.is_internal" type="warning" size="small" effect="plain">内部备注</el-tag>
                    <el-tag v-else-if="item.r.user.role !== 'user'" type="primary" size="small" effect="plain">
                        {{ item.r.user.role === 'admin' ? '管理员' : '客服' }}
                    </el-tag>
                    <el-tooltip :content="item.r.created_at" placement="top">
                        <span class="time">{{ timeAgo(item.r.created_at) }}</span>
                    </el-tooltip>
                </div>
                <div class="bubble" :class="{ staff: item.r.user.role !== 'user', mine: item.r.user.id === auth.user?.id, internal: item.r.is_internal }">{{ item.r.content }}</div>
            </div>
        </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { avatarColor } from '../../utils/avatar';
import { timeAgo } from '../../time';

const props = defineProps({ replies: { type: Array, default: () => [] } });
const auth = useAuthStore();

function dayLabel(dateStr) {
    const d = new Date(String(dateStr).replace(' ', 'T'));
    const now = new Date();
    const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diff = Math.round((startOf(now) - startOf(d)) / 86400000);
    if (diff === 0) return '今天';
    if (diff === 1) return '昨天';
    if (d.getFullYear() === now.getFullYear()) return `${d.getMonth() + 1}月${d.getDate()}日`;
    return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`;
}

// 在相邻两条不同日期的回复之间插入日期分隔条
const items = computed(() => {
    const out = [];
    let lastDay = null;
    for (const r of props.replies) {
        const day = String(r.created_at || '').slice(0, 10);
        if (day && day !== lastDay) {
            out.push({ type: 'divider', key: `d-${day}-${r.id}`, label: dayLabel(r.created_at) });
            lastDay = day;
        }
        out.push({ type: 'reply', key: r.id, r });
    }
    return out;
});
</script>

<style scoped>
.chat {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 4px 0;
}
.chat-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    margin: 4px 0;
}
.chat-divider::before,
.chat-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--el-border-color-lighter);
}
.chat-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.chat-row.mine {
    flex-direction: row-reverse;
}
.chat-main {
    max-width: 78%;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.chat-row.mine .chat-meta {
    text-align: right;
}
.chat-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
.chat-meta .time {
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.bubble {
    padding: 10px 14px;
    border-radius: 4px 12px 12px 12px;
    background: var(--el-fill-color-light);
    white-space: pre-wrap;
    line-height: 1.7;
    word-break: break-word;
    text-align: left;
}
.bubble.staff {
    background: var(--el-color-primary-light-9);
}
.bubble.mine {
    border-radius: 12px 4px 12px 12px;
    background: var(--el-color-primary);
    color: #fff;
}
/* 内部备注：虚线琥珀色，一眼区分“客户看不到” */
.bubble.internal,
.bubble.internal.mine {
    background: var(--el-color-warning-light-9);
    color: var(--el-text-color-primary);
    border: 1px dashed var(--el-color-warning-light-5);
}
@media (max-width: 768px) {
    .chat-main {
        max-width: 88%;
    }
}
</style>
