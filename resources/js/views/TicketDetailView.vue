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

                    <ChatArea :replies="ticket.replies || []" />
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
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';
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
const ticket = ref(null);
const categories = ref([]);
const agents = ref([]);
const ratingSaving = ref(false);
const ratingForm = reactive({ rating: 0, comment: '' });

const canRate = computed(() =>
    auth.user && ticket.value
    && ticket.value.user?.id === auth.user.id
    && ticket.value.status === 'closed',
);

async function load() {
    const { data } = await http.get(`/tickets/${route.params.id}`);
    ticket.value = data;
}

function onOpsSaved(data) {
    ticket.value = { ...ticket.value, ...data };
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
        if (ticket.value) ticket.value.replies_count = list.length;
        ElMessage.info(`【${payload.reply.user.name}】有新回复`);
    });
});

// 切换工单时重新加载
watch(() => route.params.id, (id) => { if (id) load(); });
</script>

<style scoped>
.content {
    white-space: pre-wrap;
    color: var(--el-text-color-primary);
    line-height: 1.8;
}
</style>
