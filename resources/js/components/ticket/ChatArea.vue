<template>
    <div class="chat">
        <div
            v-for="r in replies"
            :key="r.id"
            class="chat-row"
            :class="{ mine: r.user.id === auth.user?.id, internal: r.is_internal }"
        >
            <el-avatar :size="34" :style="{ background: avatarColor(r.user.name) }">{{ r.user.name?.[0] }}</el-avatar>
            <div class="chat-main">
                <div class="chat-meta">
                    <b>{{ r.user.name }}</b>
                    <el-tag v-if="r.is_internal" type="warning" size="small" effect="plain">内部备注</el-tag>
                    <el-tag v-else-if="r.user.role !== 'user'" type="primary" size="small" effect="plain">
                        {{ r.user.role === 'admin' ? '管理员' : '客服' }}
                    </el-tag>
                    <el-tooltip :content="r.created_at" placement="top">
                        <span class="time">{{ timeAgo(r.created_at) }}</span>
                    </el-tooltip>
                </div>
                <div class="bubble" :class="{ staff: r.user.role !== 'user', mine: r.user.id === auth.user?.id, internal: r.is_internal }">{{ r.content }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useAuthStore } from '../../stores/auth';
import { avatarColor } from '../../utils/avatar';
import { timeAgo } from '../../time';

defineProps({ replies: { type: Array, default: () => [] } });
const auth = useAuthStore();
</script>

<style scoped>
.chat {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 4px 0;
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
