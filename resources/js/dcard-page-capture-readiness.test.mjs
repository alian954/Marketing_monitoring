import assert from 'node:assert/strict';
import test from 'node:test';
import { hasDcardCaptureReadySignal } from './dcard-page-capture-readiness.mjs';

test('Dcard article content is ready when the comments section appears', () => {
    assert.equal(hasDcardCaptureReadySignal('貼文標題\n全部留言\n留言內容'), true);
    assert.equal(hasDcardCaptureReadySignal('貼文標題\n所有留言'), true);
});

test('Dcard verification pages stop the content wait without being solved', () => {
    assert.equal(hasDcardCaptureReadySignal('Dcard 需要確認您的連線是安全的。請稍候。'), true);
    assert.equal(hasDcardCaptureReadySignal('Attention Required | Cloudflare'), true);
    assert.equal(hasDcardCaptureReadySignal('Access Denied'), true);
});

test('an incomplete page shell does not count as ready', () => {
    assert.equal(hasDcardCaptureReadySignal('Dcard\n載入中'), false);
});
