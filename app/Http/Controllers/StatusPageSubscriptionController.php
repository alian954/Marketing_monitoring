<?php

namespace App\Http\Controllers;

use App\Jobs\SendStatusPageSubscriptionConfirmation;
use App\Models\NotificationOutbox;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StatusPageSubscriptionController extends Controller
{
    public function subscribe(Request $request, string $token): RedirectResponse
    {
        $statusPage = $this->publishedStatusPage($token);
        $publicPageUrl = route('status-pages.public', ['token' => $token]);

        if (trim((string) $request->input('website')) !== '') {
            return redirect($publicPageUrl)->with('status', $this->subscriptionRequestMessage());
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'website' => ['nullable', 'string', 'max:254'],
        ], [
            'email.required' => '請輸入電子郵件地址。',
            'email.email' => '請輸入有效的電子郵件地址。',
            'email.max' => '電子郵件地址不得超過 254 個字元。',
        ]);
        $email = trim($validated['email']);
        $emailHash = hash_hmac('sha256', mb_strtolower($email), (string) config('app.key'));
        $confirmationToken = Str::random(64);

        $subscription = DB::transaction(function () use ($statusPage, $email, $emailHash, $confirmationToken): ?StatusPageSubscription {
            $lockedStatusPage = StatusPage::query()->lockForUpdate()->findOrFail($statusPage->id);

            abort_unless($lockedStatusPage->is_published, 404);

            $subscription = StatusPageSubscription::query()
                ->where('status_page_id', $lockedStatusPage->id)
                ->where('email_hash', $emailHash)
                ->lockForUpdate()
                ->first();

            if ($subscription?->isActive()) {
                return null;
            }

            $subscription ??= new StatusPageSubscription(['status_page_id' => $lockedStatusPage->id]);
            $subscription->forceFill([
                'status_page_id' => $lockedStatusPage->id,
                'email' => $email,
                'email_hash' => $emailHash,
                'confirmation_token' => $confirmationToken,
                'confirmation_token_hash' => hash('sha256', $confirmationToken),
                'confirmation_expires_at' => now()->addDay(),
                'confirmed_at' => null,
                'unsubscribed_at' => null,
            ])->save();

            return $subscription;
        });

        if ($subscription !== null) {
            SendStatusPageSubscriptionConfirmation::dispatch($subscription->id)->afterCommit();
        }

        return redirect($publicPageUrl)->with('status', $this->subscriptionRequestMessage());
    }

    public function showConfirmation(Request $request, StatusPageSubscription $subscription): Response
    {
        $confirmationToken = $request->query('token');
        $tokenMatches = $this->confirmationTokenMatches($subscription, $confirmationToken);
        $isConfirmed = $subscription->isActive() && $tokenMatches;
        $canConfirm = ! $isConfirmed
            && $subscription->unsubscribed_at === null
            && $subscription->confirmed_at === null
            && $tokenMatches
            && $subscription->confirmation_expires_at !== null
            && $subscription->confirmation_expires_at->isFuture();

        return response()
            ->view('status-pages.subscription-confirmation', compact('subscription', 'canConfirm', 'isConfirmed'))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function confirm(Request $request, StatusPageSubscription $subscription): Response
    {
        $confirmationToken = $request->query('token');
        $updatedSubscription = DB::transaction(function () use ($subscription, $confirmationToken): ?StatusPageSubscription {
            $lockedSubscription = StatusPageSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($lockedSubscription->isActive() && $this->confirmationTokenMatches($lockedSubscription, $confirmationToken)) {
                return $lockedSubscription;
            }

            abort_unless(
                $lockedSubscription->unsubscribed_at === null
                && $lockedSubscription->confirmed_at === null
                && $this->confirmationTokenMatches($lockedSubscription, $confirmationToken)
                && $lockedSubscription->confirmation_expires_at !== null
                && $lockedSubscription->confirmation_expires_at->isFuture(),
                404,
            );

            $lockedSubscription->forceFill([
                'confirmed_at' => now(),
                'confirmation_token' => null,
                'confirmation_expires_at' => null,
            ])->save();

            return $lockedSubscription->fresh();
        });

        return response()
            ->view('status-pages.subscription-confirmation', [
                'subscription' => $updatedSubscription,
                'canConfirm' => false,
                'isConfirmed' => true,
            ])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function showUnsubscribe(StatusPageSubscription $subscription): Response
    {
        return response()
            ->view('status-pages.subscription-unsubscribe', [
                'subscription' => $subscription,
                'canUnsubscribe' => $subscription->unsubscribed_at === null,
            ])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function unsubscribe(Request $request, StatusPageSubscription $subscription): RedirectResponse
    {
        DB::transaction(function () use ($subscription): void {
            $lockedSubscription = StatusPageSubscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($lockedSubscription->unsubscribed_at !== null) {
                return;
            }

            $lockedSubscription->forceFill([
                'email' => null,
                'email_hash' => null,
                'confirmation_token' => null,
                'confirmation_token_hash' => null,
                'confirmation_expires_at' => null,
                'unsubscribed_at' => now(),
            ])->save();

            NotificationOutbox::query()
                ->where('status_page_subscription_id', $lockedSubscription->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'last_error' => '公開狀態頁訂閱已取消，通知未寄出。',
                    'updated_at' => now(),
                ]);
        });

        return redirect()->to(URL::signedRoute('status-page-subscriptions.unsubscribe', [
            'subscription' => $subscription->id,
        ]))->with('status', '已取消訂閱；後續狀態更新不會再寄送。');
    }

    private function publishedStatusPage(string $token): StatusPage
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        return StatusPage::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->where('is_published', true)
            ->firstOrFail();
    }

    private function confirmationTokenMatches(StatusPageSubscription $subscription, mixed $token): bool
    {
        return is_string($token)
            && $token !== ''
            && is_string($subscription->confirmation_token_hash)
            && hash_equals($subscription->confirmation_token_hash, hash('sha256', $token));
    }

    private function subscriptionRequestMessage(): string
    {
        return '如果此信箱可接收更新，確認信很快會寄出；請在 24 小時內完成確認。';
    }
}
