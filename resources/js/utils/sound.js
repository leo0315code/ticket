// 新通知声音提醒：Web Audio 合成短促提示音，无需音频文件
let audioCtx = null;

export function playNotifySound() {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }

        const start = audioCtx.currentTime;
        [880, 1174.7].forEach((freq, i) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, start + i * 0.12);
            gain.gain.exponentialRampToValueAtTime(0.06, start + i * 0.12 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + i * 0.12 + 0.15);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(start + i * 0.12);
            osc.stop(start + i * 0.12 + 0.2);
        });
    } catch {
        // 浏览器限制自动播放时静默失败（用户首次交互后即可正常）
    }
}
