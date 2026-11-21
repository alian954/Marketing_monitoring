export const DCARD_CAPTURE_READY_MARKERS = Object.freeze([
    '全部留言',
    '所有留言',
    '確認您的連線是安全的',
    '請稍候',
    'attention required',
    'access denied',
    'cloudflare',
]);

export function hasDcardCaptureReadySignal(visibleText) {
    const normalizedText = visibleText.toLowerCase();

    return DCARD_CAPTURE_READY_MARKERS.some((marker) => normalizedText.includes(marker.toLowerCase()));
}
