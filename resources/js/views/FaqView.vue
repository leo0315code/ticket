<template>
    <el-card style="max-width: 860px">
        <template #header>
            <div class="card-header">
                <span>帮助中心 / 常见问题</span>
                <el-button v-if="auth.isAdmin" type="primary" @click="openDialog()">新建 FAQ</el-button>
            </div>
        </template>

        <div class="search-bar">
            <el-input v-model="keyword" placeholder="搜索问题关键词，如：登录、发票、退款" clearable :prefix-icon="Search" @input="onSearch" />
            <el-select v-model="categoryFilter" placeholder="全部分类" clearable style="width: 150px">
                <el-option v-for="c in categories" :key="c" :label="c" :value="c" />
            </el-select>
        </div>

        <el-empty v-if="!loading && filtered.length === 0" :description="keyword ? '没有匹配的条目，可换个关键词' : '暂无 FAQ'" />

        <div v-for="g in groups" :key="g.name" class="group">
            <div class="group-title">{{ g.name }} <span class="group-count">{{ g.items.length }}</span></div>
            <el-collapse v-model="expanded" @change="onExpand">
                <el-collapse-item v-for="f in g.items" :key="f.id" :name="String(f.id)">
                    <template #title>
                        <span class="q">{{ f.question }}</span>
                        <el-tag v-if="!f.is_public" type="info" size="small" effect="plain" class="ops">未公开</el-tag>
                        <span v-if="auth.isAdmin" class="ops">
                            <el-button link type="primary" @click.stop="openDialog(f)">编辑</el-button>
                            <el-popconfirm title="确认删除？" @confirm="remove(f)">
                                <template #reference>
                                    <el-button link type="danger" @click.stop>删除</el-button>
                                </template>
                            </el-popconfirm>
                        </span>
                    </template>
                    <div class="a">{{ f.answer }}</div>
                    <div class="feedback">
                        <span class="fq">这条内容有帮助吗？</span>
                        <el-button size="small" :type="voted.has(f.id) ? 'default' : 'success'" plain :disabled="voted.has(f.id)" @click="vote(f, true)">
                            👍 有帮助 {{ f.helpful_yes }}
                        </el-button>
                        <el-button size="small" plain :disabled="voted.has(f.id)" @click="vote(f, false)">
                            👎 没帮助 {{ f.helpful_no }}
                        </el-button>
                        <span v-if="auth.isStaff" class="views">阅读 {{ f.views }}</span>
                    </div>
                </el-collapse-item>
            </el-collapse>
        </div>

        <div v-if="!loading && filtered.length > 0 && !auth.isStaff" class="footer-tip">
            没找到答案？<el-link type="primary" @click="$router.push('/m/new')">提交工单</el-link>，客服会尽快回复。
        </div>

        <el-dialog v-model="dialog" :title="editing ? '编辑 FAQ' : '新建 FAQ'" width="560px">
            <el-form label-width="70px">
                <el-form-item label="问题" required>
                    <el-input v-model="form.question" maxlength="200" />
                </el-form-item>
                <el-form-item label="分类">
                    <el-input v-model="form.category" maxlength="40" placeholder="如：账号、支付、使用问题" />
                </el-form-item>
                <el-form-item label="答案" required>
                    <el-input v-model="form.answer" type="textarea" :rows="6" maxlength="5000" />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="form.sort" :min="0" />
                </el-form-item>
                <el-form-item label="公开">
                    <el-switch v-model="form.is_public" />
                    <span class="hint">关闭后只有客服/管理员能看到</span>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialog = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="save">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { notifyError } from '../notify';
import { Search } from '@element-plus/icons-vue';
import http from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const faqs = ref([]);
const loading = ref(true);
const dialog = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = reactive({ question: '', answer: '', sort: 0, category: '', is_public: true });

const keyword = ref('');
const categoryFilter = ref('');
const expanded = ref([]);
const voted = ref(new Set());
let searchTimer = null;

const categories = computed(() => [...new Set(faqs.value.map((f) => f.category).filter(Boolean))]);

const filtered = computed(() =>
    faqs.value.filter((f) => ! categoryFilter.value || f.category === categoryFilter.value)
);

const groups = computed(() => {
    const map = new Map();
    for (const f of filtered.value) {
        const name = f.category || '其他';
        if (! map.has(name)) map.set(name, []);
        map.get(name).push(f);
    }
    return [...map.entries()].map(([name, items]) => ({ name, items }));
});

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/faqs', { params: keyword.value ? { q: keyword.value } : {} });
        faqs.value = data.data;
        voted.value = new Set();
    } finally {
        loading.value = false;
    }
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 250);
}

function onExpand(ids) {
    const opened = ids[ids.length - 1];
    const faq = faqs.value.find((f) => String(f.id) === String(opened));
    if (! faq) return;
    http.post(`/faqs/${faq.id}/view`).then(({ data }) => { faq.views = data.views; }).catch(() => {});
}

async function vote(f, helpful) {
    try {
        const { data } = await http.post(`/faqs/${f.id}/feedback`, { helpful });
        f.helpful_yes = data.helpful_yes;
        f.helpful_no = data.helpful_no;
        voted.value = new Set([...voted.value, f.id]);
        ElMessage.success('感谢反馈');
    } catch (e) {
        notifyError(e.response?.data?.message || '提交失败');
    }
}

function openDialog(row = null) {
    editing.value = row;
    form.question = row?.question || '';
    form.answer = row?.answer || '';
    form.sort = row?.sort ?? 0;
    form.category = row?.category || '';
    form.is_public = row?.is_public ?? true;
    dialog.value = true;
}

async function save() {
    if (! form.question.trim() || ! form.answer.trim()) {
        notifyError('请填写问题和答案');
        return;
    }
    saving.value = true;
    try {
        if (editing.value) {
            await http.put(`/faqs/${editing.value.id}`, { ...form });
        } else {
            await http.post('/faqs', { ...form });
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
    await http.delete(`/faqs/${row.id}`);
    ElMessage.success('已删除');
    await load();
}

onMounted(load);
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.search-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}
.group {
    margin-bottom: 18px;
}
.group-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--el-text-color-regular);
    margin-bottom: 8px;
}
.group-count {
    font-weight: 400;
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.q {
    font-weight: 600;
}
.ops {
    margin-left: 12px;
}
.a {
    white-space: pre-wrap;
    line-height: 1.8;
    color: var(--el-text-color-regular);
}
.feedback {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
.fq {
    color: var(--el-text-color-secondary);
}
.views {
    margin-left: auto;
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.footer-tip {
    margin-top: 8px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
.hint {
    margin-left: 10px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
</style>
