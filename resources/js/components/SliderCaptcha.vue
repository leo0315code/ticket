<template>
    <div class="slider-captcha" :class="{ done: verified, fail: failed }">
        <div class="track" ref="trackRef">
            <span class="track-tip">{{ verified ? '✓ 验证通过' : failed ? '验证失败，请重试' : '按住滑块拖到最右侧' }}</span>
            <div
                class="thumb"
                :class="{ done: verified }"
                :style="{ left: thumbLeft + 'px' }"
                @mousedown="startDrag"
                @touchstart.prevent="startDrag"
            >
                <el-icon v-if="!verified"><DArrowRight /></el-icon>
                <el-icon v-else><Check /></el-icon>
            </div>
            <div class="filled" :style="{ width: thumbLeft + thumbSize / 2 + 'px' }" />
        </div>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Check, DArrowRight } from '@element-plus/icons-vue';
import http from '../api';

const emit = defineEmits(['verified']);

const trackRef = ref();
const thumbLeft = ref(0);
const verified = ref(false);
const failed = ref(false);
const thumbSize = 40;

let dragging = false;
let startX = 0;
let startLeft = 0;
let capKey = null;
let trackWidth = 0;

async function issue() {
    capKey = null;
    const { data } = await http.get('/captcha/issue');
    capKey = data.key;
}

function startDrag(e) {
    if (verified.value || ! capKey) return;
    dragging = true;
    failed.value = false;
    startX = e.type === 'touchstart' ? e.touches[0].clientX : e.clientX;
    startLeft = thumbLeft.value;
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    window.addEventListener('touchmove', onMove);
    window.addEventListener('touchend', onUp);
}

function onMove(e) {
    if (! dragging) return;
    const x = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
    const max = trackWidth - thumbSize - 4;
    thumbLeft.value = Math.max(0, Math.min(max, startLeft + x - startX));
}

async function onUp() {
    if (! dragging) return;
    dragging = false;
    window.removeEventListener('mousemove', onMove);
    window.removeEventListener('mouseup', onUp);
    window.removeEventListener('touchmove', onMove);
    window.removeEventListener('touchend', onUp);

    const max = trackWidth - thumbSize - 4;
    if (thumbLeft.value >= max - 6) {
        // 拖到底：向后端核销换一次性票据
        try {
            const { data } = await http.post('/captcha/verify', { key: capKey });
            verified.value = true;
            emit('verified', data.ticket);
        } catch {
            reset();
            failed.value = true;
        }
    } else {
        // 没拖到底弹回
        thumbLeft.value = 0;
    }
}

function reset() {
    verified.value = false;
    thumbLeft.value = 0;
    emit('verified', null);
    issue();
}

function onResize() {
    trackWidth = trackRef.value?.offsetWidth ?? 0;
    if (! verified.value) thumbLeft.value = 0;
}

onMounted(() => {
    onResize();
    window.addEventListener('resize', onResize);
    issue();
});
onBeforeUnmount(() => window.removeEventListener('resize', onResize));

defineExpose({ reset });
</script>

<style scoped>
.slider-captcha {
    margin: 4px 0 14px;
}
.track {
    position: relative;
    height: 44px;
    border-radius: 10px;
    background: var(--el-fill-color-light);
    overflow: hidden;
    user-select: none;
}
.filled {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    background: var(--el-color-primary-light-8);
    transition: background 0.3s;
}
.slider-captcha.done .filled {
    background: var(--el-color-success-light-8);
}
.track-tip {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: var(--el-text-color-secondary);
    pointer-events: none;
    z-index: 1;
}
.slider-captcha.done .track-tip {
    color: var(--el-color-success);
}
.slider-captcha.fail .track-tip {
    color: var(--el-color-danger);
}
.thumb {
    position: absolute;
    top: 2px;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: var(--el-color-primary);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: grab;
    z-index: 2;
    font-size: 18px;
    transition: left 0.15s ease-out;
}
.thumb:active {
    cursor: grabbing;
}
.thumb.done {
    background: var(--el-color-success);
}
</style>
