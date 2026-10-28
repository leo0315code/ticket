<template>
    <el-card style="max-width: 1100px">
        <template #header>
            <div class="card-header">
                <span>用户管理</span>
                <el-button type="primary" @click="openCreate">新增账号</el-button>
            </div>
        </template>

        <div class="filters">
            <el-input
                v-model="filters.q"
                placeholder="搜索姓名 / 账号 / 邮箱"
                clearable
                style="width: 220px"
                @keyup.enter="load(1)"
                @clear="load(1)"
            />
            <el-select v-model="filters.role" placeholder="全部角色" clearable style="width: 140px" @change="load(1)">
                <el-option v-for="r in roleOptions" :key="r.name" :label="r.label" :value="r.name" />
            </el-select>
            <el-select v-model="filters.is_active" placeholder="全部状态" clearable style="width: 120px" @change="load(1)">
                <el-option label="启用" :value="1" />
                <el-option label="停用" :value="0" />
            </el-select>
            <el-button @click="load(1)">查询</el-button>
            <el-button link @click="resetFilters">重置</el-button>
        </div>

        <el-table :data="users" v-loading="loading" stripe>
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column label="姓名" width="120">
                <template #default="{ row }">
                    <el-link type="primary" underline="never" @click="openDetail(row)">{{ row.name }}</el-link>
                </template>
            </el-table-column>
            <el-table-column prop="username" label="账号" width="120" />
            <el-table-column prop="email" label="邮箱" min-width="170">
                <template #default="{ row }">
                    <span v-if="row.email">{{ row.email }}</span>
                    <span v-else class="muted">未绑定</span>
                </template>
            </el-table-column>
            <el-table-column label="角色" width="120">
                <template #default="{ row }">
                    <el-tag size="small" :type="row.role === 'admin' ? 'danger' : (row.role === 'user' ? 'info' : 'success')">
                        {{ row.role_label || row.role }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="在线" width="80" align="center">
                <template #default="{ row }">
                    <el-tooltip :content="row.is_online ? `在线（${row.last_seen_at || ''}）` : '离线'" placement="top">
                        <span class="presence-dot" :class="{ on: row.is_online }" />
                    </el-tooltip>
                </template>
            </el-table-column>
            <el-table-column label="状态" width="90">
                <template #default="{ row }">
                    <el-tag size="small" :type="row.is_active ? 'success' : 'info'">{{ row.is_active ? '启用' : '停用' }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="tickets_count" label="提交" width="70" align="center" />
            <el-table-column prop="assigned_tickets_count" label="受理" width="70" align="center" />
            <el-table-column label="最后登录" width="150">
                <template #default="{ row }">
                    <span v-if="row.last_login_at">{{ row.last_login_at }}</span>
                    <span v-else class="muted">从未登录</span>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="210" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                    <el-button link type="primary" @click="doReset(row)">重置密码</el-button>
                    <el-button
                        v-if="row.id !== auth.user?.id"
                        link
                        :type="row.is_active ? 'danger' : 'success'"
                        @click="toggleActive(row)"
                    >{{ row.is_active ? '停用' : '启用' }}</el-button>
                </template>
            </el-table-column>
        </el-table>

        <div class="pager">
            <el-pagination
                layout="total, prev, pager, next"
                :total="total"
                :page-size="perPage"
                :current-page="page"
                @current-change="load"
            />
        </div>

        <!-- 新增账号 -->
        <el-dialog v-model="createDialog" title="新增账号" width="460px">
            <el-form label-width="80px">
                <el-form-item label="姓名" required>
                    <el-input v-model="createForm.name" maxlength="50" placeholder="如：客服小王" />
                </el-form-item>
                <el-form-item label="账号" required>
                    <el-input v-model="createForm.username" maxlength="30" placeholder="登录用，2-30 位中文/字母/数字/下划线" />
                </el-form-item>
                <el-form-item label="角色" required>
                    <el-select v-model="createForm.role" style="width: 100%">
                        <el-option v-for="r in roleOptions" :key="r.name" :label="r.label" :value="r.name" />
                    </el-select>
                </el-form-item>
                <el-form-item label="邮箱">
                    <el-input v-model="createForm.email" placeholder="选填，用于邮件通知" />
                </el-form-item>
                <el-form-item label="初始密码" required>
                    <el-input v-model="createForm.password" placeholder="至少 8 位" show-password />
                    <el-button link size="small" @click="createForm.password = randomPassword()">随机生成</el-button>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="createDialog = false">取消</el-button>
                <el-button type="primary" :loading="creating" @click="create">创建</el-button>
            </template>
        </el-dialog>

        <!-- 编辑账号 -->
        <el-dialog v-model="editDialog" title="编辑账号" width="460px">
            <el-form label-width="80px">
                <el-form-item label="姓名" required>
                    <el-input v-model="editForm.name" maxlength="50" />
                </el-form-item>
                <el-form-item label="邮箱">
                    <el-input v-model="editForm.email" placeholder="选填，用于邮件通知" />
                </el-form-item>
                <el-form-item label="角色">
                    <el-select v-model="editForm.role" style="width: 100%" :disabled="editForm.id === auth.user?.id">
                        <el-option v-for="r in roleOptions" :key="r.name" :label="r.label" :value="r.name" />
                    </el-select>
                </el-form-item>
                <el-form-item label="自动分配">
                    <el-switch v-model="editForm.auto_assign" active-text="参与" inactive-text="不参与" />
                    <div class="muted" style="font-size: 12px">不参与的账号不会被自动派单（如管理员/主管），仍可被手动指派</div>
                </el-form-item>
                <el-form-item label="状态">
                    <el-switch
                        v-model="editForm.is_active"
                        active-text="启用"
                        inactive-text="停用"
                        :disabled="editForm.id === auth.user?.id"
                    />
                    <div class="muted" style="font-size: 12px">停用后该账号立即退出登录，且无法再登录</div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editDialog = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="saveEdit">保存</el-button>
            </template>
        </el-dialog>

        <!-- 重置密码结果（一次性展示） -->
        <el-dialog v-model="resetDialog" title="密码已重置" width="420px">
            <p style="margin: 0 0 8px">新密码（仅显示这一次，请立即转告本人）：</p>
            <el-input :model-value="resetPasswordValue" readonly>
                <template #append>
                    <el-button @click="copyReset">复制</el-button>
                </template>
            </el-input>
            <p style="color: var(--el-text-color-secondary); font-size: 12px; margin-top: 10px">
                该用户所有登录已失效，需用新密码重新登录。
            </p>
        </el-dialog>

        <el-drawer v-model="detailOpen" title="用户详情" size="440px">
            <div v-if="detail" v-loading="detailLoading">
                <el-descriptions :column="1" border size="small">
                    <el-descriptions-item label="姓名">{{ detail.user.name }}</el-descriptions-item>
                    <el-descriptions-item label="账号">{{ detail.user.username }}</el-descriptions-item>
                    <el-descriptions-item label="邮箱">{{ detail.user.email || '未绑定' }}</el-descriptions-item>
                    <el-descriptions-item label="角色">{{ detail.user.role_label || detail.user.role }}</el-descriptions-item>
                    <el-descriptions-item label="状态">{{ detail.user.is_active ? '启用' : '停用' }}</el-descriptions-item>
                    <el-descriptions-item label="最后登录">{{ detail.user.last_login_at || '从未登录' }}</el-descriptions-item>
                </el-descriptions>

                <el-row :gutter="8" class="detail-stats">
                    <el-col :span="12">
                        <div class="stat-box">
                            <div class="stat-num">{{ detail.stats.submitted }}</div>
                            <div class="stat-label">提交工单（进行中 {{ detail.stats.submitted_active }}）</div>
                        </div>
                    </el-col>
                    <el-col :span="12">
                        <div class="stat-box">
                            <div class="stat-num">{{ detail.stats.assigned }}</div>
                            <div class="stat-label">受理工单（进行中 {{ detail.stats.assigned_active }}）</div>
                        </div>
                    </el-col>
                </el-row>

                <h4 class="detail-title">最近提交</h4>
                <el-empty v-if="! detail.recent_submitted.length" description="暂无工单" :image-size="56" />
                <div v-for="t in detail.recent_submitted" :key="t.id" class="recent-item">
                    <router-link :to="ticketLink(t)" class="recent-title">{{ t.no }} · {{ t.title }}</router-link>
                    <el-tag size="small" :type="statusType(t.status)">{{ STATUS_MAP[t.status]?.label || t.status }}</el-tag>
                </div>

                <template v-if="detail.recent_assigned.length">
                    <h4 class="detail-title">最近受理</h4>
                    <div v-for="t in detail.recent_assigned" :key="`a-${t.id}`" class="recent-item">
                        <router-link :to="ticketLink(t)" class="recent-title">{{ t.no }} · {{ t.title }}</router-link>
                        <el-tag size="small" :type="statusType(t.status)">{{ STATUS_MAP[t.status]?.label || t.status }}</el-tag>
                    </div>
                </template>
            </div>
        </el-drawer>
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { notifyError } from '../notify';
import http from '../api';
import { adminPath } from '../paths';
import { STATUS_MAP } from '../constants';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const users = ref([]);
const roleOptions = ref([]);
const loading = ref(true);

const page = ref(1);
const perPage = ref(20);
const total = ref(0);
const filters = reactive({ q: '', role: '', is_active: '' });

const createDialog = ref(false);
const creating = ref(false);
const createForm = reactive({ name: '', username: '', email: '', password: '', role: 'agent' });

const editDialog = ref(false);
const saving = ref(false);
const editForm = reactive({ id: null, name: '', email: '', role: 'agent', is_active: true, auto_assign: true });

const resetDialog = ref(false);
const resetPasswordValue = ref('');

const detailOpen = ref(false);
const detailLoading = ref(false);
const detail = ref(null);

function statusType(status) {
    return { open: 'danger', in_progress: 'warning', pending_resolved: 'info', closed: 'success' }[status] || 'info';
}

function ticketLink(t) {
    return `${adminPath('tickets')}/${t.id}`;
}

async function openDetail(row) {
    detail.value = null;
    detailOpen.value = true;
    detailLoading.value = true;
    try {
        const { data } = await http.get(`/users/${row.id}`);
        detail.value = data;
    } finally {
        detailLoading.value = false;
    }
}

function randomPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    return Array.from({ length: 12 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

async function loadRoles() {
    const { data } = await http.get('/roles/options');
    roleOptions.value = data.data ?? data;
}

async function load(target = page.value) {
    loading.value = true;
    try {
        const { data } = await http.get('/users', {
            params: {
                page: target,
                per_page: perPage.value,
                q: filters.q || undefined,
                role: filters.role || undefined,
                is_active: filters.is_active === '' ? undefined : filters.is_active,
            },
        });
        users.value = data.data;
        total.value = data.meta?.total ?? data.data.length;
        page.value = data.meta?.current_page ?? target;
    } finally {
        loading.value = false;
    }
}

function resetFilters() {
    Object.assign(filters, { q: '', role: '', is_active: '' });
    load(1);
}

function openCreate() {
    Object.assign(createForm, { name: '', username: '', email: '', password: randomPassword(), role: 'agent' });
    createDialog.value = true;
}

async function create() {
    if (! createForm.name || ! createForm.username || ! createForm.password) {
        notifyError('请填写姓名、账号和初始密码');
        return;
    }
    creating.value = true;
    try {
        await http.post('/users', { ...createForm, email: createForm.email || null });
        createDialog.value = false;
        ElMessage.success(`账号 ${createForm.username} 已创建`);
        await load(1);
    } catch (e) {
        const errors = e.response?.data?.errors;
        notifyError(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message || '创建失败'));
    } finally {
        creating.value = false;
    }
}

function openEdit(row) {
    Object.assign(editForm, {
        id: row.id,
        name: row.name,
        email: row.email || '',
        role: row.role,
        is_active: row.is_active !== false,
        auto_assign: row.auto_assign !== false,
    });
    editDialog.value = true;
}

async function saveEdit() {
    saving.value = true;
    try {
        await http.put(`/users/${editForm.id}`, {
            name: editForm.name,
            email: editForm.email || null,
            role: editForm.role,
            is_active: editForm.is_active,
            auto_assign: editForm.auto_assign,
        });
        editDialog.value = false;
        ElMessage.success('已保存');
        await load();
    } catch (e) {
        notifyError(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}

async function toggleActive(row) {
    const next = ! row.is_active;
    await ElMessageBox.confirm(
        next ? `确认启用「${row.name}」？` : `确认停用「${row.name}」？停用后对方会立刻退出登录。`,
        next ? '启用账号' : '停用账号',
        { type: 'warning' }
    );
    await http.put(`/users/${row.id}`, { is_active: next });
    ElMessage.success(next ? '已启用' : '已停用');
    await load();
}

async function doReset(row) {
    await ElMessageBox.confirm(`确认重置「${row.name}」的密码？其所有登录将失效。`, '重置密码', { type: 'warning' });
    const { data } = await http.put(`/users/${row.id}/reset-password`);
    resetPasswordValue.value = data.password;
    resetDialog.value = true;
}

function copyReset() {
    navigator.clipboard?.writeText(resetPasswordValue.value);
    ElMessage.success('已复制');
}

onMounted(async () => {
    await loadRoles();
    await load(1);
});
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.filters {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.pager {
    display: flex;
    justify-content: flex-end;
    margin-top: 14px;
}
.muted {
    color: var(--el-text-color-secondary);
}
.detail-stats {
    margin: 16px 0 4px;
}
.stat-box {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    padding: 10px 12px;
}
.stat-num {
    font-size: 22px;
    font-weight: 600;
}
.stat-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
}
.detail-title {
    margin: 18px 0 8px;
}
.recent-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px dashed var(--el-border-color-lighter);
    font-size: 13px;
}
.recent-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.presence-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--el-color-info-light-5);
}

.presence-dot.on {
    background: var(--el-color-success);
    box-shadow: 0 0 0 3px var(--el-color-success-light-8);
}
</style>
