import { ElMessage } from 'element-plus';

/**
 * 失败提示统一出口：常驻（不自动消失）+ 可手动关闭 + 相同内容合并。
 * 表单内的校验错误用页面内红条，这里只用于“操作失败”。
 */
export function notifyError(message) {
    ElMessage({ type: 'error', message: String(message || '操作失败，请稍后再试'), duration: 0, showClose: true, grouping: true });
}
