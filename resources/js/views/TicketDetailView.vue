<template>
    <div v-if="ticket" class="detail">
        <el-page-header @back="$router.back()" style="margin-bottom: 16px">
            <template #content>
                <span style="font-weight: 600">{{ ticket.no }} · {{ ticket.title }}</span>
            </template>
        </el-page-header>

        <el-row :gutter="16">
            <el-col :xs="24" :sm="16">
                <el-card>
                    <el-descriptions :column="3" border size="small" style="margin-bottom: 16px">
                        <el-descriptions-item label="状态">
                            <el-tag :type="STATUS_MAP[ticket.status].type" size="small">{{ STATUS_MAP[ticket.status].label }}</el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="优先级">
                            <el-tag :type="PRIORITY_MAP[ticket.priority].type" effect="plain" size="small">{{ PRIORITY_MAP[ticket.priority].label }}</el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="分类">{{ ticket.category?.name || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="提交人">{{ ticket.user?.name }}</el-descriptions-item>
                        <el-descriptions-item label="受理客服">{{ ticket.agent?.name || '未指派' }}</el-descriptions-item>
                        <el-descriptions-item label="创建时间">{{ ticket.created_at }}</el-descriptions-item>
                        <el-descriptions-item label="SLA 截止">
                            <span v-if="ticket.sla_due_at" :style="ticket.overdue && !ticket.first_response_at ? 'color:#f56c6c;font-weight:600' : ''">
                                {{ ticket.sla_due_at }}{{ ticket.overdue && !ticket.first_response_at ? '（已超时）' : '' }}
                            </span>
                            <span v-else>-</span>
                        </el-descriptions-item>
                        <el-descriptions-item label="首次响应">{{ ticket.first_response_at || '未响应' }}</el-descriptions-item>
                        <el-descriptions-item label="">{{ '' }}</el-descriptions-item>
                    </el-descriptions>

                    <h4>工单内容</h4>
                    <p class="content">{{ ticket.content }}</p>

                    <el-divider>回复（{{ ticket.replies?.length || 0 }}）</el-divider>

                    <div class="chat">
                        <div
                            v-for="r in ticket.replies"
                            :key="r.id"
                            class="chat-row"
                            :class="{ mine: r.user.id === auth.user?.id }"
                        >
                            <el-avatar :size="34" :style="{ background: avatarColor(r.user.name) }">{{ r.user.name?.[0] }}</el-avatar>
                            <div class="chat-main">
                                <div class="chat-meta">
                                    <b>{{ r.user.name }}</b>
                                    <el-tag v-if="r.user.role !== 'user'" type="primary" size="small" effect="plain">
                                        {{ r.user.role === 'admin' ? '管理员' : '客服' }}
                                    </el-tag>
                                    <span class="time">{{ r.created_at }}</span>
                                </div>
                                <div class="bubble" :class="{ staff: r.user.role !== 'user', mine: r.user.id === auth.user?.id }">{{ r.content }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="reply-box">
                        <div v-for="(f, i) in replyFiles" :key="f.name + i" class="file-chip">
                            <span class="file-name">{{ f.name }}</span>
                            <span class="file-size">{{ formatSize(f.size) }}</span>
                            <el-icon class="file-del" @click="replyFiles.splice(i, 1)"><CircleCloseFilled /></el-icon>
                        </div>
                        <div class="reply-wrap">
                            <el-input
                                ref="replyInputRef"
                                v-model="reply"
                                type="textarea"
                                :rows="3"
                                :placeholder="auth.isStaff ? '输入回复内容…（@账号 可提醒同事）' : '输入回复内容…'"
                                @input="onReplyInput"
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
                                :on-change="addReplyFile"
                            >
                                <el-button :loading="replyUploading" title="添加图片/附件">📎 附件</el-button>
                            </el-upload>
                            <el-button type="primary" :loading="replying" :disabled="!reply.trim() && replyFiles.length === 0" @click="sendReply">回复</el-button>
                        </div>
                    </div>
                </el-card>
            </el-col>

            <el-col :xs="24" :sm="8">
                <el-card v-if="auth.isStaff" header="处理操作">
                    <el-form label-width="80px" size="default">
                        <el-form-item label="状态">
                            <el-select v-model="ops.status" style="width: 100%">
                                <el-option v-for="(v, k) in STATUS_MAP" :key="k" :label="v.label" :value="k" />
                            </el-select>
                        </el-form-item>
                        <el-form-item label="优先级">
                            <el-select v-model="ops.priority" style="width: 100%">
                                <el-option v-for="(v, k) in PRIORITY_MAP" :key="k" :label="v.label" :value="k" />
                            </el-select>
                        </el-form-item>
                        <el-form-item label="分类">
                            <el-select v-model="ops.category_id" clearable style="width: 100%">
                                <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
                            </el-select>
                        </el-form-item>
                        <el-form-item label="指派给">
                            <el-select v-model="ops.agent_id" clearable placeholder="未指派" style="width: 100%">
                                <el-option v-for="a in agents" :key="a.id" :label="a.name" :value="a.id" />
                            </el-select>
                        </el-form-item>
                        <el-form-item>
                            <el-button type="primary" :loading="saving" @click="save">保存</el-button>
                        </el-form-item>
                    </el-form>
                </el-card>

                <el-card v-else header="说明">
                    <p style="margin: 0; color: #909399; line-height: 1.8">
                        客服回复后工单状态变为「处理中」，请耐心等待；若问题已解决，客服会将状态更新为「待确认」并关闭工单。
                    </p>
                </el-card>

                <el-card v-if="ticket.resolved_at || ticket.closed_at" header="时间记录" style="margin-top: 16px">
                    <p v-if="ticket.resolved_at">解决时间：{{ ticket.resolved_at }}</p>
                    <p v-if="ticket.closed_at" style="margin: 0">关闭时间：{{ ticket.closed_at }}</p>
                </el-card>

                <el-card v-if="canRate" header="满意度评价" style="margin-top: 16px">
                    <div v-if="ticket.rating" style="line-height: 2">
                        <el-rate :model-value="ticket.rating" disabled />
                        <div v-if="ticket.rating_comment" style="color: #606266">{{ ticket.rating_comment }}</div>
                    </div>
                    <template v-else>
                        <el-rate v-model="ratingForm.rating" show-text :texts="['很差', '较差', '一般', '满意', '非常满意']" style="margin-bottom: 8px" />
                        <el-input v-model="ratingForm.comment" type="textarea" :rows="2" maxlength="200" placeholder="评价一下本次服务（可选）" />
                        <el-button type="primary" style="margin-top: 8px" :loading="ratingSaving" :disabled="!ratingForm.rating" @click="submitRating">提交评价</el-button>
                    </template>
                </el-card>

                <el-card header="附件" style="margin-top: 16px">
                    <div v-if="ticket.attachments?.length">
                        <div v-for="a in ticket.attachments" :key="a.id" class="attach-row">
                            <el-icon><Document /></el-icon>
                            <el-link type="primary" @click="downloadAttachment(a)">{{ a.original_name }}</el-link>
                            <span class="attach-meta">{{ formatSize(a.size) }} · {{ a.user?.name }}</span>
                        </div>
                    </div>
                    <p v-else style="color: #909399; margin: 0 0 8px">暂无附件</p>
                    <el-upload :show-file-list="false" :http-request="uploadAttachment" multiple>
                        <el-button size="small" :loading="uploading">上传附件（≤10MB）</el-button>
                    </el-upload>
                </el-card>

                <el-card header="操作记录" style="margin-top: 16px">
                    <el-timeline>
                        <el-timeline-item
                            v-for="l in ticket.logs"
                            :key="l.id"
                            :timestamp="`${l.created_at} · ${l.user?.name}`"
                            placement="top"
                        >
                            <b>{{ ACTION_LABELS[l.action] || l.action }}</b>
                            <span v-if="l.detail">：{{ l.detail }}</span>
                        </el-timeline-item>
                    </el-timeline>
                </el-card>
            </el-col>
        </el-row>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';
import { CircleCloseFilled, Document } from '@element-plus/icons-vue';
import http from '../api';
import { connectRealtime } from '../realtime';
import { avatarColor } from '../utils/avatar';
import { useAuthStore } from '../stores/auth';
import { STATUS_MAP, PRIORITY_MAP } from '../constants';

const ACTION_LABELS = {
    created: '创建工单',
    replied: '回复',
    status_changed: '状态变更',
    assigned: '指派',
    priority_changed: '优先级调整',
    category_changed: '分类调整',
    rated: '满意度评价',
    attachment_uploaded: '上传附件',
};

const route = useRoute();
const auth = useAuthStore();
const ticket = ref(null);
const categories = ref([]);
const agents = ref([]);
const reply = ref('');
const replyFiles = ref([]);
const replyUploading = ref(false);

function addReplyFile(file) {
    if (file.size > 10 * 1024 * 1024) {
        ElMessage.warning(`「${file.name}」超过 10MB，未添加`);
        return;
    }
    if (replyFiles.value.length >= 3) {
        ElMessage.warning('一条回复最多附带 3 个附件');
        return;
    }
    replyFiles.value.push({ name: file.name, size: file.size, raw: file.raw });
}
const replying = ref(false);
const replyInputRef = ref();
const mentionVisible = ref(false);
const mentionUsers = ref([]);
const mentionIndex = ref(0);
let mentionStart = -1;
let mentionTimer = null;

// 检测光标前是否在输入 @提及，防抖拉取匹配人员
function onReplyInput() {
    if (! auth.isStaff) return; // @提及仅客服/管理员可用
    clearTimeout(mentionTimer);
    mentionTimer = setTimeout(() => {
        const el = replyInputRef.value?.textarea ?? replyInputRef.value?.$el?.querySelector('textarea');
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
        const q = m[1];
        http.get('/mention-search', { params: { q } }).then(({ data }) => {
            // 光标位置已变化则放弃本次结果
            const el2 = replyInputRef.value?.textarea ?? replyInputRef.value?.$el?.querySelector('textarea');
            if (! el2 || (el2.selectionStart ?? 0) !== caret) return;
            mentionUsers.value = data.data;
            mentionIndex.value = 0;
            mentionVisible.value = true;
        }).catch(() => {});
    }, 180);
}

/** 键盘导航：↑↓ 移动高亮、Enter 选中、Esc 关闭（面板关闭时 Enter 正常换行） */
function onReplyKeydown(e) {
    if (! mentionVisible.value) {
        if (e.key === 'Escape') mentionVisible.value = false;
        return;
    }
    if (e.key === 'ArrowDown' || e.key === 'Down') {
        e.preventDefault();
        moveMention(1);
    } else if (e.key === 'ArrowUp' || e.key === 'Up') {
        e.preventDefault();
        moveMention(-1);
    } else if (e.key === 'Enter' || e.key === 'Enter'.toLowerCase()) {
        if (mentionUsers.value.length > 0) {
            e.preventDefault();
            pickMention(mentionUsers.value[mentionIndex.value]);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        mentionVisible.value = false;
    }
}

function moveMention(delta) {
    if (! mentionVisible.value || mentionUsers.value.length === 0) return;
    const len = mentionUsers.value.length;
    mentionIndex.value = (mentionIndex.value + delta + len) % len;
}

/** 回车：面板打开时选中项（没动过则第一项），否则放行换行 */
function enterMention(e) {
    if (! mentionVisible.value || mentionUsers.value.length === 0) return;
    pickMention(mentionUsers.value[mentionIndex.value]);
}

function pickMention(u) {
    const el = replyInputRef.value?.textarea ?? replyInputRef.value?.$el?.querySelector('textarea');
    const caret = el?.selectionStart ?? reply.value.length;
    reply.value = reply.value.slice(0, mentionStart) + `@${u.username} ` + reply.value.slice(caret);
    mentionVisible.value = false;
    nextTick(() => {
        const el2 = replyInputRef.value?.textarea ?? replyInputRef.value?.$el?.querySelector('textarea');
        el2?.focus();
        const at = mentionStart + u.username.length + 2;
        el2?.setSelectionRange(at, at);
    });
}
const saving = ref(false);
const uploading = ref(false);
const ratingSaving = ref(false);
const ratingForm = reactive({ rating: 0, comment: '' });
const ops = reactive({ status: '', priority: '', category_id: '', agent_id: '' });

const canRate = computed(() =>
    auth.user && ticket.value
    && ticket.value.user?.id === auth.user.id
    && ticket.value.status === 'closed',
);

function formatSize(bytes) {
    if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

function scrollReplyBox() {
    nextTick(() => {
        const el = document.querySelector('.reply-box');
        el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
}

async function downloadAttachment(a) {
    const res = await http.get(`/tickets/${route.params.id}/attachments/${a.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = a.original_name;
    link.click();
    URL.revokeObjectURL(url);
}

async function uploadAttachment({ file }) {
    uploading.value = true;
    try {
        const fd = new FormData();
        fd.append('file', file);
        await http.post(`/tickets/${route.params.id}/attachments`, fd);
        ElMessage.success('附件已上传');
        await load();
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '上传失败');
    } finally {
        uploading.value = false;
    }
}

async function submitRating() {
    ratingSaving.value = true;
    try {
        const { data } = await http.post(`/tickets/${route.params.id}/rating`, {
            rating: ratingForm.rating,
            rating_comment: ratingForm.comment || null,
        });
        ticket.value = { ...ticket.value, ...data };
        ElMessage.success('感谢您的评价');
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '评价失败');
    } finally {
        ratingSaving.value = false;
    }
}

async function load() {
    const { data } = await http.get(`/tickets/${route.params.id}`);
    ticket.value = data;
    ops.status = data.status;
    ops.priority = data.priority;
    ops.category_id = data.category?.id || null;
    ops.agent_id = data.agent?.id || null;
}

async function sendReply() {
    if (!reply.value.trim() && replyFiles.value.length === 0) {
        ElMessage.warning('请输入回复内容或添加附件');
        return;
    }
    replying.value = true;
    try {
        await http.post(`/tickets/${route.params.id}/replies`, { content: reply.value || '（附件）' });
        reply.value = '';
        // 回复成功后上传附件（挂到工单附件区）
        if (replyFiles.value.length > 0) {
            replyUploading.value = true;
            let ok = 0;
            for (const f of replyFiles.value) {
                try {
                    const fd = new FormData();
                    fd.append('file', f.raw);
                    await http.post(`/tickets/${route.params.id}/attachments`, fd);
                    ok++;
                } catch (e) {
                    ElMessage.warning(`附件「${f.name}」上传失败，可在附件区重试`);
                }
            }
            replyFiles.value = [];
            if (ok > 0) ElMessage.success(`${ok} 个附件已添加`);
            replyUploading.value = false;
        }
        await load();
        ElMessage.success('回复成功');
    } finally {
        replying.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const { data } = await http.put(`/tickets/${route.params.id}`, {
            status: ops.status,
            priority: ops.priority,
            category_id: ops.category_id || null,
            agent_id: ops.agent_id || null,
        });
        ticket.value = { ...ticket.value, ...data };
        ElMessage.success('已保存');
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await load();
    const [catRes, usersRes] = await Promise.all([
        http.get('/categories'),
        auth.isStaff ? http.get('/agents') : Promise.resolve({ data: { data: [] } }),
    ]);
    categories.value = catRes.data.data;
    agents.value = usersRes.data.data;

    // el-input 的 attrs 不一定透传到 inner textarea，键盘导航直接绑原生元素最稳
    nextTick(() => {
        replyInputRef.value?.textarea?.addEventListener("keydown", onReplyKeydown);
    });

    // 即时消息：别人回复当前工单时聊天区实时追加气泡（去重）
    connectRealtime((payload) => {
        if (payload.type !== 'reply' || payload.ticket_id !== Number(route.params.id)) {
            return;
        }
        const list = ticket.value?.replies ?? (ticket.value.replies = []);
        if (list.some((r) => r.id === payload.reply.id)) {
            return;
        }
        list.push({ ...payload.reply });
        if (ticket.value) ticket.value.replies_count = list.length;
        ElMessage.info(`【${payload.reply.user.name}】有新回复`);
        scrollReplyBox();
    });
});
</script>

<style scoped>
.content {
    white-space: pre-wrap;
    color: #303133;
    line-height: 1.8;
}
.reply {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 12px;
    background: #f5f7fa;
}
.reply.staff {
    background: #ecf5ff;
}

/* 聊天式回复 */
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
}
.bubble.staff {
    background: var(--el-color-primary-light-9);
}
.bubble.mine {
    border-radius: 12px 4px 12px 12px;
    background: var(--el-color-primary);
    color: #fff;
}
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
.reply-actions {
    margin-top: 8px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
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
.attach-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 0;
}
.attach-meta {
    color: #909399;
    font-size: 12px;
}
@media (max-width: 768px) {
    .chat-main {
        max-width: 88%;
    }
}
</style>
