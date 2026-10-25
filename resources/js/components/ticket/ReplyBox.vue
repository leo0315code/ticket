<template>
    <div class="reply-box">
        <div v-for="(f, i) in files" :key="f.name + i" class="file-chip">
            <span class="file-name">{{ f.name }}</span>
            <span class="file-size">{{ formatSize(f.size) }}</span>
            <el-icon class="file-del" @click="files.splice(i, 1)"><CircleCloseFilled /></el-icon>
        </div>

        <div class="reply-wrap">
            <el-input
                ref="inputRef"
                v-model="reply"
                type="textarea"
                :rows="3"
                :placeholder="auth.isStaff ? '输入回复内容…（@账号 可提醒同事）' : '输入回复内容…'"
                @input="onInput"
            />
            <div v-if="mentionVisible" class="mention-panel">
                <div v-if="mentionUsers.length === 0" class="mention-empty">没有匹配的人</div>
                <div
                    v-for="(u, idx) in mentionUsers"
                    :key="u.id"
                    class="mention-item"
                    :class="{ active: mentionIndex === idx }"
                    @mousedown.prevent="pickMention(u)"
                    @mouseenter="mentionIndex = idx"
                >
                    <el-avatar :size="24" :style="{ background: avatarColor(u.name) }">{{ u.name?.[0] }}</el-avatar>
                    <span class="mention-name">{{ u.name }}</span>
                    <span class="mention-username">@{{ u.username }}</span>
                    <el-tag v-if="u.role !== 'user'" size="small" effect="plain">
                        {{ u.role === 'admin' ? '管理员' : '客服' }}
                    </el-tag>
                </div>
                <div class="mention-tip">↑↓ 选择 · Enter 确认 · Esc 关闭</div>
            </div>
        </div>

        <div class="reply-actions">
            <el-upload
                :show-file-list="false"
                :auto-upload="false"
                multiple
                accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip"
                :on-change="addFile"
            >
                <el-button :loading="uploading" title="添加图片/附件">📎 附件</el-button>
            </el-upload>
            <el-button type="primary" :loading="sending" :disabled="!reply.trim() && files.length === 0" @click="send">回复</el-button>
        </div>
    </div>
</template>

<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { CircleCloseFilled } from '@element-plus/icons-vue';
import http from '../../api';
import { useAuthStore } from '../../stores/auth';
import { avatarColor } from '../../utils/avatar';

const props = defineProps({ ticketId: { type: [Number, String], required: true } });
const emit = defineEmits(['sent']);

const auth = useAuthStore();
const reply = ref('');
const files = ref([]);
const sending = ref(false);
const uploading = ref(false);

const inputRef = ref();
const mentionVisible = ref(false);
const mentionUsers = ref([]);
const mentionIndex = ref(0);
let mentionStart = -1;
let mentionTimer = null;

function onInput() {
    if (! auth.isStaff) return; // @提及仅客服/管理员可用
    clearTimeout(mentionTimer);
    mentionTimer = setTimeout(async () => {
        const el = textarea();
        if (! el) return;
        const caret = el.selectionStart ?? 0;
        const before = reply.value.slice(0, caret);
        const m = before.match(/@([\u4e00-\u9fa5a-zA-Z0-9_]{0,30})$/u);
        if (! m) {
            mentionVisible.value = false;
            mentionStart = -1;
            return;
        }
        mentionStart = caret - m[1].length - 1;
        const { data } = await http.get('/mention-search', { params: { q: m[1] } }).catch(() => ({ data: { data: [] } }));
        if ((textarea()?.selectionStart ?? 0) !== caret) return; // 光标已移动，放弃
        mentionUsers.value = data.data;
        mentionIndex.value = 0;
        mentionVisible.value = true;
    }, 180);
}

function textarea() {
    return inputRef.value?.textarea ?? inputRef.value?.$el?.querySelector('textarea');
}

/** 键盘导航（原生绑定：el-input attrs 不保证透传到 inner textarea） */
function onKeydown(e) {
    if (! mentionVisible.value) {
        if (e.key === 'Escape') mentionVisible.value = false;
        return;
    }
    if (e.key === 'ArrowDown' || e.key === 'Down') {
        e.preventDefault();
        mentionIndex.value = (mentionIndex.value + 1 + mentionUsers.value.length) % mentionUsers.value.length;
    } else if (e.key === 'ArrowUp' || e.key === 'Up') {
        e.preventDefault();
        mentionIndex.value = (mentionIndex.value - 1 + mentionUsers.value.length) % mentionUsers.value.length;
    } else if (e.key === 'Enter' && mentionUsers.value.length > 0) {
        e.preventDefault();
        pickMention(mentionUsers.value[mentionIndex.value]);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        mentionVisible.value = false;
    }
}

function pickMention(u) {
    const el = textarea();
    const caret = el?.selectionStart ?? reply.value.length;
    reply.value = reply.value.slice(0, mentionStart) + `@${u.username} ` + reply.value.slice(caret);
    mentionVisible.value = false;
    nextTick(() => {
        const el2 = textarea();
        el2?.focus();
        const at = mentionStart + u.username.length + 2;
        el2?.setSelectionRange(at, at);
    });
}

function addFile(file) {
    if (file.size > 10 * 1024 * 1024) {
        ElMessage.warning(`「${file.name}」超过 10MB，未添加`);
        return;
    }
    if (files.value.length >= 3) {
        ElMessage.warning('一条回复最多附带 3 个附件');
        return;
    }
    files.value.push({ name: file.name, size: file.size, raw: file.raw });
}

function formatSize(bytes) {
    if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

async function send() {
    if (! reply.value.trim() && files.value.length === 0) {
        ElMessage.warning('请输入回复内容或添加附件');
        return;
    }
    sending.value = true;
    try {
        await http.post(`/tickets/${props.ticketId}/replies`, { content: reply.value || '（附件）' });
        reply.value = '';

        if (files.value.length > 0) {
            uploading.value = true;
            let ok = 0;
            for (const f of files.value) {
                try {
                    const fd = new FormData();
                    fd.append('file', f.raw);
                    await http.post(`/tickets/${props.ticketId}/attachments`, fd);
                    ok++;
                } catch (e) {
                    ElMessage.warning(`附件「${f.name}」上传失败，可在附件区重试`);
                }
            }
            files.value = [];
            if (ok > 0) ElMessage.success(`${ok} 个附件已添加`);
            uploading.value = false;
        }
        emit('sent');
        ElMessage.success('回复成功');
    } finally {
        sending.value = false;
    }
}

onMounted(() => {
    nextTick(() => textarea()?.addEventListener('keydown', onKeydown));
});
</script>

<style scoped>
.reply-box {
    margin-top: 16px;
}
.file-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    padding: 8px 12px;
    background: var(--el-fill-color-light);
    border-radius: 10px;
    font-size: 13px;
}
.file-chip .file-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.file-chip .file-size {
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.file-chip .file-del {
    color: var(--el-text-color-secondary);
    cursor: pointer;
}
.reply-wrap {
    position: relative;
}
.mention-panel {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 100%;
    margin-bottom: 4px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
    box-shadow: var(--el-box-shadow-light);
    max-height: 220px;
    overflow-y: auto;
    z-index: 50;
}
.mention-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
}
.mention-item:hover,
.mention-item.active {
    background: var(--el-fill-color-light);
}
.mention-item.active {
    outline: 1px solid var(--el-color-primary-light-7);
}
.mention-name {
    color: var(--el-text-color-primary);
    font-weight: 600;
}
.mention-username {
    color: var(--el-text-color-secondary);
}
.mention-empty {
    padding: 10px 12px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
.mention-tip {
    padding: 6px 12px;
    border-top: 1px solid var(--el-border-color-lighter);
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.reply-actions {
    margin-top: 8px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>
