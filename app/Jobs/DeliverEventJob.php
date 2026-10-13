<?php

namespace App\Jobs;

use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Rules\SafeWebhookUrl;
use App\Services\Events\PlatformEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class DeliverEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = EventDelivery::query()->with(['event', 'subscription'])->find($this->deliveryId);
        if ($delivery === null || $delivery->state !== EventDelivery::PENDING || $delivery->next_attempt_at?->isFuture()) {
            return;
        }
        $subscription = $delivery->subscription;
        if ($subscription === null || ! $subscription->is_enabled) {
            $delivery->update(['state' => EventDelivery::FAILED, 'last_error' => 'The subscription is disabled.', 'next_attempt_at' => null]);

            return;
        }

        $url = (string) $subscription->url;
        try {
            if (Validator::make(['url' => $url], ['url' => ['required', 'url', new SafeWebhookUrl]])->fails()) {
                throw new \RuntimeException('The URL points to a private or unsafe address.');
            }
            $options = SafeWebhookUrl::httpClientOptions($url);
            $body = json_encode($delivery->event->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = now()->getTimestamp();
            $response = Http::withOptions($options + ['allow_redirects' => false])
                ->timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Coolify-Events/1',
                    'Coolify-Event' => $delivery->event->type,
                    'Coolify-Delivery' => $delivery->uuid,
                    'Coolify-Signature' => PlatformEvents::signature((string) $subscription->secret, $body, $timestamp),
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            $this->finish($delivery, $subscription, $response->successful(), $response->status(), $response->successful() ? null : "HTTP {$response->status()}");
        } catch (Throwable $e) {
            $this->finish($delivery, $subscription, false, null, Str::limit($e->getMessage(), 240));
        }
    }

    private function finish(EventDelivery $delivery, EventSubscription $subscription, bool $success, ?int $status, ?string $error): void
    {
        $attempts = $delivery->attempts + 1;
        if ($success) {
            $delivery->update(['state' => EventDelivery::DELIVERED, 'attempts' => $attempts, 'response_status' => $status, 'last_error' => null, 'delivered_at' => now(), 'next_attempt_at' => null]);
            $subscription->update(['consecutive_failures' => 0, 'last_delivery_at' => now(), 'last_response_status' => $status]);

            return;
        }

        $retry = $attempts < EventDelivery::MAX_ATTEMPTS;
        $delivery->update([
            'state' => $retry ? EventDelivery::PENDING : EventDelivery::FAILED,
            'attempts' => $attempts,
            'response_status' => $status,
            'last_error' => $error,
            'next_attempt_at' => $retry ? now()->addSeconds(EventDelivery::BACKOFF[$attempts - 1] ?? 14400) : null,
        ]);

        $failures = $subscription->consecutive_failures + 1;
        $disable = $failures >= EventSubscription::DISABLE_AFTER_FAILURES;
        $subscription->update([
            'consecutive_failures' => $failures,
            'last_delivery_at' => now(),
            'last_response_status' => $status,
            'is_enabled' => $disable ? false : $subscription->is_enabled,
            'disabled_reason' => $disable ? "Disabled after {$failures} failed deliveries in a row. Last error: {$error}" : $subscription->disabled_reason,
        ]);
        if ($disable) {
            auditLog('system.event_subscription.disabled', ['team_id' => $subscription->team_id, 'subscription_uuid' => $subscription->uuid, 'failures' => $failures], 'warning');
        }
    }
}
