<?php

namespace App\Http\Controllers;

use App\Jobs\SendNotificationOutbox;
use App\Models\LineMessagingChannel;
use App\Models\LineNotificationTarget;
use App\Models\NotificationOutbox;
use App\Services\LineMessagingApi;
use App\Services\LineMessagingApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LineMessagingIntegrationController extends Controller
{
    public function show(Request $request): Response
    {
        $channel = LineMessagingChannel::query()->first();
        $targets = $channel === null
            ? collect()
            : $channel->targets()->orderByRaw('left_at is not null')->latest('joined_at')->get();

        return response()
            ->view('integrations.line', [
                'channel' => $channel,
                'targets' => $targets,
                'webhookUrl' => $channel === null ? null : route('line.webhook', $channel->webhook_key),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, LineMessagingApi $lineMessagingApi): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspace()?->getKey();

        if ($workspaceId === null) {
            abort(404);
        }

        $channel = LineMessagingChannel::query()->first();
        $accessToken = trim((string) $request->input('channel_access_token'));
        $channelSecret = trim((string) $request->input('channel_secret'));
        $validator = Validator::make([
            'channel_access_token' => $accessToken === '' ? null : $accessToken,
            'channel_secret' => $channelSecret === '' ? null : $channelSecret,
        ], [
            'channel_access_token' => [$channel === null ? 'required' : 'nullable', 'string', 'max:1024'],
            'channel_secret' => [$channel === null ? 'required' : 'nullable', 'string', 'regex:/\A[0-9a-f]{32}\z/i'],
        ], [
            'channel_access_token.required' => '請輸入頻道存取權杖（Channel access token）。',
            'channel_access_token.max' => '頻道存取權杖過長。',
            'channel_secret.required' => '請輸入頻道密鑰（Channel secret）。',
            'channel_secret.regex' => '頻道密鑰應為 32 個英數字元。',
        ]);

        if ($validator->fails()) {
            return redirect()->route('integrations.line.edit')->withErrors($validator, 'lineChannel');
        }

        $botInfo = null;

        if ($accessToken !== '') {
            try {
                $botInfo = $lineMessagingApi->botInfo($accessToken);
            } catch (LineMessagingApiException $exception) {
                return redirect()->route('integrations.line.edit')
                    ->withErrors(['channel_access_token' => $exception->getMessage()], 'lineChannel');
            }

            if ($channel !== null && $channel->bot_user_id !== null && $botInfo['user_id'] !== null && $botInfo['user_id'] !== $channel->bot_user_id) {
                return redirect()->route('integrations.line.edit')
                    ->withErrors(['channel_access_token' => '此權杖屬於另一個 LINE 官方帳號。若要更換官方帳號，請先中斷連線再重新設定。'], 'lineChannel');
            }
        }

        $channel ??= new LineMessagingChannel([
            'user_id' => $request->user()->workspaceOwnerId(),
            'workspace_id' => $workspaceId,
        ]);

        if ($accessToken !== '') {
            $channel->channel_access_token = $accessToken;
        }

        if ($channelSecret !== '') {
            $channel->channel_secret = $channelSecret;
        }

        if ($botInfo !== null) {
            $channel->forceFill([
                'bot_user_id' => $botInfo['user_id'],
                'bot_display_name' => $botInfo['display_name'],
                'bot_basic_id' => $botInfo['basic_id'],
            ]);
        }

        $channel->save();

        return redirect()->route('integrations.line.edit')
            ->with('status', 'LINE 官方帳號設定已儲存。');
    }

    public function destroy(): RedirectResponse
    {
        LineMessagingChannel::query()->first()?->delete();

        return redirect()->route('integrations.line.edit')
            ->with('status', '已中斷 LINE 官方帳號連線，使用 LINE 的警示規則將不再推播。');
    }

    public function sendTest(Request $request, LineNotificationTarget $lineNotificationTarget): RedirectResponse
    {
        if (! $lineNotificationTarget->isActive()) {
            return redirect()->route('integrations.line.edit')
                ->withErrors(['line_target' => '官方帳號已離開此對象，無法傳送測試訊息。']);
        }

        DB::transaction(function () use ($lineNotificationTarget): void {
            $outbox = NotificationOutbox::query()->create([
                'incident_id' => null,
                'monitor_id' => null,
                'event_key' => hash('sha256', implode(':', [
                    NotificationOutbox::EVENT_TYPE_TEST,
                    (string) Str::uuid(),
                    NotificationOutbox::CHANNEL_LINE,
                    $lineNotificationTarget->getKey(),
                ])),
                'channel' => NotificationOutbox::CHANNEL_LINE,
                'recipient' => $lineNotificationTarget->target_id,
                'event_type' => NotificationOutbox::EVENT_TYPE_TEST,
                'status' => 'pending',
                'subject' => '['.config('app.name').'] LINE 測試通知',
                'body' => '這是一則測試通知。若收到此訊息，表示行銷警示可以推播到「'.$lineNotificationTarget->label().'」。',
            ]);

            SendNotificationOutbox::dispatch($outbox->id)->afterCommit();
        });

        return redirect()->route('integrations.line.edit')
            ->with('status', '已排入 LINE 測試通知，可到通知紀錄查看寄送結果。');
    }

    public function destroyTarget(LineNotificationTarget $lineNotificationTarget): RedirectResponse
    {
        $lineNotificationTarget->delete();

        return redirect()->route('integrations.line.edit')
            ->with('status', '已移除 LINE 推播對象，使用此對象的警示規則將不再推播到 LINE。');
    }
}
