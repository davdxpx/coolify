<?php

namespace App\Services\Events;

use App\Jobs\DeliverEventJob;
use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Models\PlatformEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outbox for team events: the event and one delivery per matching subscription are stored in
 * one transaction, then delivered by a queued job and retried from the stored state.
 */
class PlatformEvents
{
    /**
     * @param  array<string, mixed>  $data  Identifiers and states only, never secrets.
     */
    public static function record(string $type, ?int $teamId, string $subjectType, ?string $subjectUuid, array $data = [], ?string $correlationId = null): ?PlatformEvent
    {
        try {
            [$event, $deliveryIds] = DB::transaction(function () use ($type, $teamId, $subjectType, $subjectUuid, $data, $correlationId) {
                $event = PlatformEvent::create([
                    'team_id' => $teamId,
                    'type' => $type,
                    'subject_type' => $subjectType,
                    'subject_uuid' => $subjectUuid,
                    'correlation_id' => $correlationId,
                    'data' => $data,
                    'occurred_at' => now(),
                ]);
                $deliveryIds = [];
                if ($teamId !== null) {
                    $subscriptions = EventSubscription::query()->where('team_id', $teamId)->where('is_enabled', true)->get();
                    foreach ($subscriptions as $subscription) {
                        if ($subscription->wants($type)) {
                            $deliveryIds[] = EventDelivery::create([
                                'platform_event_id' => $event->id,
                                'event_subscription_id' => $subscription->id,
                                'state' => EventDelivery::PENDING,
                                'next_attempt_at' => now(),
                            ])->id;
                        }
                    }
                }

                return [$event, $deliveryIds];
            });

            foreach ($deliveryIds as $deliveryId) {
                DeliverEventJob::dispatch($deliveryId)->afterCommit();
            }

            return $event;
        } catch (Throwable $e) {
            // Events must never break the operation that emits them.
            Log::warning("Recording event {$type} failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Header value `t=<unix time>,v1=<hex HMAC-SHA256 of "<time>.<body>">`, the same scheme Stripe uses.
     */
    public static function signature(string $secret, string $body, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /**
     * Checks a received `Coolify-Signature` header; consumers can port these few lines.
     */
    public static function verify(string $secret, string $body, string $header, int $toleranceSeconds = 300): bool
    {
        if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $matches)) {
            return false;
        }

        return abs(time() - (int) $matches[1]) <= $toleranceSeconds
            && hash_equals(self::signature($secret, $body, (int) $matches[1]), $header);
    }
}
