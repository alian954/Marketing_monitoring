<?php

namespace App\Jobs;

use App\Mail\StatusPageSubscriptionConfirmation;
use App\Models\StatusPageSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendStatusPageSubscriptionConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public readonly int $subscriptionId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $subscription = StatusPageSubscription::query()
            ->with('statusPage')
            ->find($this->subscriptionId);

        if (
            $subscription === null
            || $subscription->isActive()
            || $subscription->unsubscribed_at !== null
            || $subscription->email === null
            || $subscription->confirmation_token === null
            || $subscription->confirmation_expires_at === null
            || $subscription->confirmation_expires_at->isPast()
        ) {
            return;
        }

        $confirmationUrl = URL::route('status-page-subscriptions.confirm', [
            'subscription' => $subscription->id,
            'token' => $subscription->confirmation_token,
        ]);

        Mail::to($subscription->email)->send(new StatusPageSubscriptionConfirmation(
            $subscription->statusPage->title,
            $confirmationUrl,
            $subscription->confirmation_expires_at->toDateTimeImmutable(),
        ));
    }
}
