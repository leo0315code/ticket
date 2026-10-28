<template>
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
        <p style="margin: 0; color: var(--el-text-color-secondary); line-height: 1.8">
            客服回复后工单状态变为「处理中」，请耐心等待；若问题已解决，客服会将状态更新为「待确认」并关闭工单。
        </p>
    </el-card>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { notifyError } from '../../notify';
import http from '../../api';
import { useAuthStore } from '../../stores/auth';
import { STATUS_MAP, PRIORITY_MAP } from '../../constants';

const props = defineProps({
    ticket: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved']);

const auth = useAuthStore();
const saving = ref(false);
const ops = reactive({ status: '', priority: '', category_id: '', agent_id: '' });

watch(() => props.ticket, (t) => {
    if (! t) return;
    ops.status = t.status;
    ops.priority = t.priority;
    ops.category_id = t.category?.id ?? null;
    ops.agent_id = t.agent?.id ?? null;
}, { immediate: true });

async function save() {
    saving.value = true;
    try {
        const { data } = await http.put(`/tickets/${props.ticket.id}`, {
            status: ops.status,
            priority: ops.priority,
            category_id: ops.category_id || null,
            agent_id: ops.agent_id || null,
        });
        emit('saved', data);
        ElMessage.success('已保存');
    } catch (e) {
        notifyError(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}
</script>
