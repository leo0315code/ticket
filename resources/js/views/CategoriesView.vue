<template>
    <div style="max-width: 720px">
    <el-card>
        <template #header>
            <div class="card-header">
                <span>分类管理</span>
                <el-button type="primary" @click="openDialog()">新建分类</el-button>
            </div>
        </template>

        <el-table :data="categories" v-loading="loading" stripe>
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="name" label="名称" width="160" />
            <el-table-column prop="description" label="描述" />
            <el-table-column label="默认客服" width="110">
                <template #default="{ row }">{{ row.default_agent?.name || '—' }}</template>
            </el-table-column>
            <el-table-column label="操作" width="140">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openDialog(row)">编辑</el-button>
                    <el-popconfirm title="确认删除该分类？" @confirm="remove(row)">
                        <template #reference>
                            <el-button link type="danger">删除</el-button>
                        </template>
                    </el-popconfirm>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialog" :title="editing ? '编辑分类' : '新建分类'" width="420px">
            <el-form label-width="60px">
                <el-form-item label="名称" required>
                    <el-input v-model="form.name" maxlength="50" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="form.description" maxlength="200" />
                </el-form-item>
                <el-form-item label="默认客服">
                    <el-select v-model="form.default_agent_id" placeholder="不指定" clearable style="width: 100%">
                        <el-option v-for="a in agents" :key="a.id" :label="a.name" :value="a.id" />
                    </el-select>
                    <span class="hint">开启「新工单自动分配」时，该分类的新工单优先给 TA</span>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialog = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="save">保存</el-button>
            </template>
        </el-dialog>
    </el-card>

    <el-card style="margin-top: 16px">
        <template #header>
            <div class="card-header">
                <span>自动规则（新工单命中关键词 → 自动分类/优先级）</span>
                <el-button type="primary" @click="openRuleDialog()">新建规则</el-button>
            </div>
        </template>

        <el-table :data="rules" v-loading="rulesLoading" stripe>
            <el-table-column prop="name" label="规则名" width="130" />
            <el-table-column prop="keywords" label="关键词" show-overflow-tooltip />
            <el-table-column label="动作" width="170">
                <template #default="{ row }">
                    <el-tag v-if="row.set_category" size="small" style="margin-right: 4px">分类→{{ row.set_category }}</el-tag>
                    <el-tag v-if="row.set_priority" size="small" type="warning">{{ PRIORITY_MAP[row.set_priority] || row.set_priority }}</el-tag>
                    <el-text v-if="!row.set_category && !row.set_priority" type="info" size="small">无动作</el-text>
                </template>
            </el-table-column>
            <el-table-column label="启用" width="70" align="center">
                <template #default="{ row }">
                    <el-switch :model-value="row.is_active" size="small" @change="(v) => toggleRule(row, v)" />
                </template>
            </el-table-column>
            <el-table-column label="操作" width="130">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openRuleDialog(row)">编辑</el-button>
                    <el-popconfirm title="确认删除该规则？" @confirm="removeRule(row)">
                        <template #reference>
                            <el-button link type="danger">删除</el-button>
                        </template>
                    </el-popconfirm>
                </template>
            </el-table-column>
            <template #empty><el-text type="info">暂无规则，例如：关键词“发票,报销” → 分类“财务问题”</el-text></template>
        </el-table>

        <el-dialog v-model="ruleDialog" :title="editingRule ? '编辑规则' : '新建规则'" width="460px">
            <el-form label-width="80px">
                <el-form-item label="规则名" required>
                    <el-input v-model="ruleForm.name" maxlength="50" placeholder="如：发票类问题" />
                </el-form-item>
                <el-form-item label="关键词" required>
                    <el-input v-model="ruleForm.keywords" maxlength="500" placeholder="逗号或空格分隔，任一命中即触发" />
                    <span class="hint">匹配新工单的标题和正文，不区分大小写</span>
                </el-form-item>
                <el-form-item label="设为分类">
                    <el-select v-model="ruleForm.set_category_id" placeholder="不修改" clearable style="width: 100%">
                        <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="设为优先级">
                    <el-select v-model="ruleForm.set_priority" placeholder="不修改" clearable style="width: 100%">
                        <el-option v-for="(label, key) in PRIORITY_MAP" :key="key" :label="label" :value="key" />
                    </el-select>
                    <span class="hint">修改优先级会按新优先级重算响应/解决时限</span>
                </el-form-item>
                <el-form-item label="启用">
                    <el-switch v-model="ruleForm.is_active" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="ruleDialog = false">取消</el-button>
                <el-button type="primary" :loading="ruleSaving" @click="saveRule">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { notifyError } from '../notify';
import { PRIORITY_MAP } from '../constants';
import http from '../api';

const categories = ref([]);
const loading = ref(true);
const dialog = ref(false);
const saving = ref(false);
const editing = ref(null);
const agents = ref([]);
const form = reactive({ name: '', description: '', default_agent_id: null });

const rules = ref([]);
const rulesLoading = ref(false);
const ruleDialog = ref(false);
const ruleSaving = ref(false);
const editingRule = ref(null);
const ruleForm = reactive({ name: '', keywords: '', set_category_id: null, set_priority: null, is_active: true });

async function loadRules() {
    rulesLoading.value = true;
    try {
        const { data } = await http.get('/ticket-rules');
        rules.value = data.data;
    } catch (e) {
        notifyError(e.response?.data?.message || '规则加载失败');
    } finally {
        rulesLoading.value = false;
    }
}

function openRuleDialog(row = null) {
    editingRule.value = row;
    ruleForm.name = row?.name || '';
    ruleForm.keywords = row?.keywords || '';
    ruleForm.set_category_id = row?.set_category_id ?? null;
    ruleForm.set_priority = row?.set_priority ?? null;
    ruleForm.is_active = row?.is_active ?? true;
    ruleDialog.value = true;
}

async function saveRule() {
    if (! ruleForm.name.trim() || ! ruleForm.keywords.trim()) {
        notifyError('请填写规则名和关键词');
        return;
    }
    ruleSaving.value = true;
    try {
        if (editingRule.value) {
            await http.put(`/ticket-rules/${editingRule.value.id}`, { ...ruleForm });
        } else {
            await http.post('/ticket-rules', { ...ruleForm });
        }
        ElMessage.success('已保存');
        ruleDialog.value = false;
        loadRules();
    } catch (e) {
        notifyError(e.response?.data?.message || '保存失败');
    } finally {
        ruleSaving.value = false;
    }
}

async function toggleRule(row, value) {
    try {
        await http.put(`/ticket-rules/${row.id}`, {
            name: row.name, keywords: row.keywords,
            set_category_id: row.set_category_id, set_priority: row.set_priority,
            is_active: value,
        });
        row.is_active = value;
    } catch (e) {
        notifyError(e.response?.data?.message || '操作失败');
    }
}

async function removeRule(row) {
    try {
        await http.delete(`/ticket-rules/${row.id}`);
        ElMessage.success('已删除');
        loadRules();
    } catch (e) {
        notifyError(e.response?.data?.message || '删除失败');
    }
}

async function loadAgents() {
    try {
        const { data } = await http.get('/agents');
        agents.value = data.data ?? data;
    } catch { /* 无权时保持空 */ }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/categories');
        categories.value = data.data;
    } finally {
        loading.value = false;
    }
}

function openDialog(row = null) {
    editing.value = row;
    form.name = row?.name || '';
    form.default_agent_id = row?.default_agent_id ?? null;
    form.description = row?.description || '';
    dialog.value = true;
}

async function save() {
    if (!form.name.trim()) {
        notifyError('请输入分类名称');
        return;
    }
    saving.value = true;
    try {
        if (editing.value) {
            await http.put(`/categories/${editing.value.id}`, { ...form });
        } else {
            await http.post('/categories', { ...form });
        }
        dialog.value = false;
        ElMessage.success('已保存');
        await load();
    } catch (e) {
        notifyError(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    await http.delete(`/categories/${row.id}`);
    ElMessage.success('已删除');
    await load();
}

onMounted(() => { load(); loadAgents(); loadRules(); });
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
</style>

<style scoped>
.hint {
    margin-left: 8px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}
</style>
