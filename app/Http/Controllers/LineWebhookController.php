<?php

namespace App\Http\Controllers;

use App\Models\LineMessagingChannel;
use App\Models\LineNotificationTarget;
use App\Services\LineMessagingApi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LineWebhookController extends Controller
{
    private const MAX_EVENTS = 100;

    public function __invoke(Request $request, string $webhookKey, LineMessagingApi $lineMessagingApi): Response
    {
        $channel = LineMessagingChannel::query()
            ->withoutGlobalScopes()
            ->where('webhook_key', $webhookKey)
            ->first();

        if ($channel === null) {
            abort(404);
        }

        $signature = (string) $request->header('X-Line-Signature', '');

        if (! LineMessagingApi::hasValidSignature((string) $channel->channel_secret, $request->getContent(), $signature)) {
            abort(401);
        }

        $events = $request->json('events');

        if (is_array($events)) {
            foreach (array_slice(array_values($events), 0, self::MAX_EVENTS) as $event) {
                if (is_array($event)) {
                    $this->recordEvent($channel, $event, $lineMessagingApi);
                }
            }
        }

        $channel->forceFill(['last_webhook_received_at' => now()])->save();

        return response()->noContent(200);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordEvent(LineMessagingChannel $channel, array $event, LineMessagingApi $lineMessagingApi): void
    {
        $eventType = $event['type'] ?? null;
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        [$targetType, $targetId] = match ($source['type'] ?? null) {
            'group' => [LineNotificationTarget::TYPE_GROUP, $source['groupId'] ?? null],
            'room' => [LineNotificationTarget::TYPE_ROOM, $source['roomId'] ?? null],
            'user' => [LineNotificationTarget::TYPE_USER, $source['userId'] ?? null],
            default => [null, null],
        };

        if ($targetType === null || ! is_string($targetId) || ! LineMessagingApi::isValidTargetId($targetId)) {
            return;
        }

        $joinsTarget = ($eventType === 'join' && $targetType !== LineNotificationTarget::TYPE_USER)
            || ($eventType === 'follow' && $targetType === LineNotificationTarget::TYPE_USER);
        $leavesTarget = ($eventType === 'leave' && $targetType !== LineNotificationTarget::TYPE_USER)
            || ($eventType === 'unfollow' && $targetType === LineNotificationTarget::TYPE_USER);

        if ($leavesTarget) {
            $channel->targets()
                ->withoutGlobalScopes()
                ->where('target_id', $targetId)
                ->whereNull('left_at')
                ->update(['left_at' => now(), 'updated_at' => now()]);

            return;
        }

        if (! $joinsTarget) {
            return;
        }

        $accessToken = (string) $channel->channel_access_token;
        $displayName = match ($targetType) {
            LineNotificationTarget::TYPE_GROUP => $lineMessagingApi->groupName($accessToken, $targetId),
            LineNotificationTarget::TYPE_USER => $lineMessagingApi->profileName($accessToken, $targetId),
            default => null,
        };
        $target = $channel->targets()
            ->withoutGlobalScopes()
            ->firstOrNew(['target_id' => $targetId]);

        $target->forceFill([
            'workspace_id' => $channel->workspace_id,
            'target_type' => $targetType,
            'display_name' => $displayName ?? $target->display_name,
            'joined_at' => now(),
            'left_at' => null,
        ])->save();
    }
}
