// 根据名字生成稳定的头像配色
const PALETTE = [
    '#409eff', '#67c23a', '#e6a23c', '#f56c6c', '#9a6ce8',
    '#00b8a9', '#f6416c', '#3f72af', '#ff8c42', '#6d597a',
];

export function avatarColor(name = '') {
    let hash = 0;
    for (const ch of String(name)) {
        hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
    }
    return PALETTE[hash % PALETTE.length];
}
