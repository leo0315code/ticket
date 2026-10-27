<template>
    <div v-if="ticket" class="detail">
        <el-page-header @back="$router.back()" :style="isMobile ? 'margin-bottom: 10px' : 'margin-bottom: 16px'">
            <template #content>
                <span class="head-title">{{ ticket.no }} · {{ ticket.title }}</span>
            </template>
            <template #extra>
                <el-button size="small" :type="ticket.is_watching ? 'primary' : 'default'" plain :loading="watching" @click="toggleWatch">
                    {{ ticket.is_watching ? '已关注' : '关注' }}
                    <template v-if="ticket.watchers_count">（{{ ticket.watchers_count }}）</template>
                </el-button>
                <el-button v-if="canReopen" type="warning" plain size="small" :loading="reopening" @click="reopen">
                    问题未解决，重新打开
                </el-button>
            </template>
        </el-page-header>

        <el-row :gutter="isMobile ? 0 : 16">
            <el-col :xs="24" :sm="16">
                <el-card>
                    <el-descriptions :column="isMobile ? 2 : 3" border size="small" style="margin-bottom: 14px">
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
                        <el-descriptions-item label="解决期限">
                            <span v-if="ticket.resolution_due_at" :style="ticket.resolution_overdue ? 'color:#f56c6c;font-weight:600' : ''">
                                {{ ticket.resolution_due_at }}{{ ticket.resolution_overdue ? '（已超期）' : '' }}
                            </span>
                            <span v-else>-</span>
                        </el-descriptions-item>
                        <el-descriptions-item label="">{{ '' }}</el-descriptions-item>
                    </el-descriptions>

                    <h4>工单内容</h4>
                    <p class="content">{{ ticket.content }}</p>

                    <el-divider>回复（{{ ticket.replies_count ?? ticket.replies?.length ?? 0 }}）</el-divider>

                    <div v-if="hasEarlier" style="text-align: center; margin-bottom: 8px">
                        <el-button text size="small" :loading="loadingEarlier" @click="loadEarlier">
                            加载更早的回复（还有 {{ (ticket.replies_count ?? 0) - displayReplies.length }} 条）
                        </el-button>
                    </div>

                    <ChatArea :replies="displayReplies" />
                    <ReplyBox :ticket-id="ticket.id" @sent="load" />
                </el-card>
            </el-col>

            <el-col :xs="24" :sm="8">
                <OpsPanel
                    :ticket="ticket"
                    :categories="categories"
                    :agents="agents"
                    @saved="onOpsSaved"
                />

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

                <AttachmentPanel :ticket="ticket" @change="load" />

                <el-card v-if="auth.isStaff" header="操作记录" style="margin-top: 16px">
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
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';
import { notifyError } from '../notify';
import http from '../api';
import { connectRealtime } from '../realtime';
import { useAuthStore } from '../stores/auth';
import { STATUS_MAP, PRIORITY_MAP } from '../constants';
import ChatArea from '../components/ticket/ChatArea.vue';
import ReplyBox from '../components/ticket/ReplyBox.vue';
import OpsPanel from '../components/ticket/OpsPanel.vue';
import AttachmentPanel from '../components/ticket/AttachmentPanel.vue';

const ACTION_LABELS = {
    created: '创建工单',
    replied: '回复',
    status_changed: '状态变更',
    assigned: '指派',
    priority_changed: '优先级调整',
    category_changed: '分类调整',
    rated: '满意度评价',
    attachment_uploaded: '上传附件',
    sla_breach: 'SLA 超时',
};

const route = useRoute();
const auth = useAuthStore();
const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });
const ticket = ref(null);
const categories = ref([]);
const agents = ref([]);
const ratingSaving = ref(false);
const reopening = ref(false);
const watching = ref(false);
const loadingEarlier = ref(false);
const olderReplies = ref([]);
const ratingForm = reactive({ rating: 0, comment: '' });

// 展示用回复 = 已加载的更早分页 + 最新一页
const displayReplies = computed(() => [...olderReplies.value, ...(ticket.value?.replies || [])]);
const hasEarlier = computed(() =>
    ticket.value && (ticket.value.replies_count ?? 0) > displayReplies.value.length,
);

// 能不能重开由后端算（受后台设置的重开窗口/次数限制），前端只读结果
const canReopen = computed(() => !!ticket.value?.can_reopen);

const canRate = computed(() =>
    auth.user && ticket.value
    && ticket.value.user?.id === auth.user.id
    && ticket.value.status === 'closed',
);

async function load() {
    const { data } = await http.get(`/tickets/${route.params.id}`);
    ticket.value = data;
}

// 向前翻页：以当前已加载的最早一条为游标，避免新回复插入导致重复/遗漏
async function loadEarlier() {
    const first = displayReplies.value[0];
    if (!first || loadingEarlier.value) {
        return;
    }
    loadingEarlier.value = true;
    try {
        const { data } = await http.get(`/tickets/${route.params.id}/replies`, {
            params: { before_id: first.id, per_page: 30 },
        });
        const known = new Set(displayReplies.value.map((r) => r.id));
        const older = (data.data || []).filter((r) => !known.has(r.id));
        olderReplies.value = [...older, ...olderReplies.value];
    } catch (e) {
        notifyError(e.response?.data?.message || '加载更早的回复失败');
    } finally {
        loadingEarlier.value = false;
    }
}

function onOpsSaved(data) {
    ticket.value = { ...ticket.value, ...data };
}

async function toggleWatch() {
    watching.value = true;
    try {
        const method = ticket.value.is_watching ? 'delete' : 'post';
        const { data } = await http[method](`/tickets/${route.params.id}/watch`);
        ticket.value = { ...ticket.value, is_watching: data.watching, watchers_count: data.count };
    } catch (e) {
        notifyError(e.response?.data?.message || '操作失败');
    } finally {
        watching.value = false;
    }
}

async function reopen() {
    reopening.value = true;
    try {
        const { data } = await http.post(`/tickets/${route.params.id}/reopen`);
        ticket.value = { ...ticket.value, ...data };
        ElMessage.success('工单已重新打开，客服会尽快跟进');
    } catch (e) {
        notifyError(e.response?.data?.message || '重新打开失败');
    } finally {
        reopening.value = false;
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
        notifyError(e.response?.data?.message || '评价失败');
    } finally {
        ratingSaving.value = false;
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

    // 即时消息：别人回复当前工单时聊天区实时追加（去重）
    connectRealtime((payload) => {
        if (payload.type !== 'reply' || payload.ticket_id !== Number(route.params.id)) {
            return;
        }
        const list = ticket.value?.replies ?? (ticket.value.replies = []);
        if (list.some((r) => r.id === payload.reply.id)) {
            return;
        }
        list.push({ ...payload.reply });
        if (ticket.value) ticket.value.replies_count = (ticket.value.replies_count || 0) + 1;
        ElMessage.info(`【${payload.reply.user.name}】有新回复`);
    });
});

// 切换工单时重新加载
watch(() => route.params.id, (id) => {
    if (!id) {
        return;
    }
    olderReplies.value = [];
    load();
});
</script>

<style scoped>
.head-title {
    font-weight: 600;
    display: inline-block;
    max-width: 60vw;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
}

@media (max-width: 768px) {
    .detail :deep(.el-card__body) {
        padding: 12px;
    }
    .detail :deep(.el-descriptions__cell) {
        padding: 8px 10px;
    }
    .detail :deep(.el-page-header__content) {
        min-width: 0;
    }
    .detail h4 {
        margin: 12px 0 8px;
    }
    .detail .content {
        font-size: 14px;
        line-height: 1.7;
    }
}

.content {
    white-space: pre-wrap;
    color: var(--el-text-color-primary);
    line-height: 1.8;
}
</style>
