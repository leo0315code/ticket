<template>
    <el-card style="max-width: 1000px">
        <template #header>
            <div class="card-header">
                <span>角色权限</span>
                <el-button type="primary" @click="openCreate">新建角色</el-button>
            </div>
        </template>

        <p class="hint">
            角色决定后台能看到哪些菜单、能做哪些操作。内置的「普通用户」「客服」「管理员」不能删除；
            管理员固定拥有全部权限，客服可以按需增删。
        </p>

        <el-table :data="roles" v-loading="loading" stripe>
            <el-table-column prop="label" label="角色名称" width="150" />
            <el-table-column prop="name" label="标识" width="140">
                <template #default="{ row }"><code>{{ row.name }}</code></template>
            </el-table-column>
            <el-table-column label="权限" width="120" align="center">
                <template #default="{ row }">
                    <el-tag v-if="row.name === 'admin'" size="small" type="danger">全部</el-tag>
                    <span v-else>{{ row.permission_count }} 项</span>
                </template>
            </el-table-column>
            <el-table-column prop="users_count" label="用户数" width="90" align="center" />
            <el-table-column label="类型" width="100">
                <template #default="{ row }">
                    <el-tag size="small" :type="row.is_system ? 'warning' : 'info'">{{ row.is_system ? '内置' : '自定义' }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="160" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" :disabled="row.name === 'admin'" @click="openEdit(row)">编辑权限</el-button>
                    <el-button
                        v-if="! row.is_system"
                        link
                        type="danger"
                        @click="remove(row)"
                    >删除</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialog" :title="form.id ? '编辑角色权限' : '新建角色'" width="720px" top="6vh">
            <el-form label-width="80px">
                <el-form-item v-if="! form.id" label="标识" required>
                    <el-input v-model="form.name" maxlength="30" placeholder="小写字母开头，如 support、finance" />
                </el-form-item>
                <el-form-item label="名称" required>
                    <el-input v-model="form.label" maxlength="30" placeholder="显示给人看的名字，如：售后客服" />
                </el-form-item>
                <el-form-item label="权限">
                    <div class="perm-groups">
                        <div v-for="group in catalog" :key="group.group" class="perm-group">
                            <div class="perm-title">{{ group.group }}</div>
                            <el-checkbox-group v-model="form.permissions">
                                <el-checkbox
                                    v-for="p in group.permissions"
                                    :key="p.key"
                                    :value="p.key"
                                    :disabled="form.name === 'admin'"
                                >{{ p.label }}</el-checkbox>
                            </el-checkbox-group>
                        </div>
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialog = false">取消</el-button>
                <el-button type="primary" :loading="saving" :disabled="form.name === 'admin'" @click="save">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import http from '../api';

const roles = ref([]);
const catalog = ref([]);
const loading = ref(true);
const dialog = ref(false);
const saving = ref(false);

const form = reactive({ id: null, name: '', label: '', permissions: [] });

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/roles');
        roles.value = data.data;
        catalog.value = data.catalog;
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    Object.assign(form, { id: null, name: '', label: '', permissions: ['panel.access'] });
    dialog.value = true;
}

function openEdit(row) {
    Object.assign(form, { id: row.id, name: row.name, label: row.label, permissions: [...(row.permissions || [])] });
    dialog.value = true;
}

async function save() {
    if (! form.label || (! form.id && ! form.name)) {
        ElMessage.warning('请填写角色标识和名称');
        return;
    }
    saving.value = true;
    try {
        if (form.id) {
            await http.put(`/roles/${form.id}`, { label: form.label, permissions: form.permissions });
        } else {
            await http.post('/roles', { name: form.name, label: form.label, permissions: form.permissions });
        }
        dialog.value = false;
        ElMessage.success('已保存');
        await load();
    } catch (e) {
        const errors = e.response?.data?.errors;
        ElMessage.error(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message || '保存失败'));
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    await ElMessageBox.confirm(`确认删除角色「${row.label}」？`, '删除角色', { type: 'warning' });
    try {
        await http.delete(`/roles/${row.id}`);
        ElMessage.success('已删除');
        await load();
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '删除失败');
    }
}

onMounted(load);
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.hint {
    margin: 0 0 14px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
    line-height: 1.7;
}
.perm-groups {
    width: 100%;
}
.perm-group {
    padding: 8px 0;
    border-bottom: 1px dashed var(--el-border-color-lighter);
}
.perm-group:last-child {
    border-bottom: none;
}
.perm-title {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 4px;
}
.perm-group :deep(.el-checkbox) {
    margin-right: 16px;
}
</style>
