<?php

namespace App\Livewire\EventWebhooks;

use App\Jobs\DeliverEventJob;
use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Models\PlatformEvent;
use App\Rules\SafeWebhookUrl;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    public string $name = '';

    public string $url = '';

    /** @var list<string> */
    public array $eventTypes = ['*'];

    public ?string $createdSecret = null;

    private function ensureAdmin(): void
    {
        if (! auth()->user()?->isAdminOfTeam((int) currentTeam()->id)) {
            abort(403, 'You need at least admin permissions in this team.');
        }
    }

    public function create()
    {
        try {
            $this->ensureAdmin();
            $this->validate([
                'name' => 'required|string|max:100',
                'url' => ['required', 'string', 'max:2000', 'url:https', new SafeWebhookUrl],
                'eventTypes' => 'required|array|min:1',
                'eventTypes.*' => 'string|in:*,'.implode(',', PlatformEvent::TYPES),
            ]);
            if (EventSubscription::query()->where('team_id', currentTeam()->id)->count() >= EventSubscription::MAX_PER_TEAM) {
                throw new \RuntimeException('A team can have at most '.EventSubscription::MAX_PER_TEAM.' event webhooks.');
            }
            $secret = 'whsec_'.Str::random(40);
            $subscription = EventSubscription::create([
                'team_id' => currentTeam()->id,
                'name' => $this->name,
                'url' => $this->url,
                'secret' => $secret,
                'event_types' => in_array('*', $this->eventTypes, true) ? ['*'] : array_values($this->eventTypes),
                'created_by_user_id' => auth()->id(),
            ]);
            auditLog('ui.event_subscription.created', ['team_id' => $subscription->team_id, 'subscription_uuid' => $subscription->uuid, 'url_host' => parse_url($this->url, PHP_URL_HOST)]);
            $this->createdSecret = $secret;
            $this->reset('name', 'url');
            $this->eventTypes = ['*'];
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(string $uuid)
    {
        try {
            $this->ensureAdmin();
            $subscription = EventSubscription::query()->where('team_id', currentTeam()->id)->where('uuid', $uuid)->firstOrFail();
            $subscription->delete();
            auditLog('ui.event_subscription.deleted', ['team_id' => $subscription->team_id, 'subscription_uuid' => $uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function test(string $uuid)
    {
        try {
            $this->ensureAdmin();
            $subscription = EventSubscription::query()->where('team_id', currentTeam()->id)->where('uuid', $uuid)->firstOrFail();
            $subscription->update(['is_enabled' => true, 'disabled_reason' => null, 'consecutive_failures' => 0]);
            $event = PlatformEvent::create(['team_id' => null, 'type' => 'ping', 'subject_type' => 'event_subscription', 'subject_uuid' => $subscription->uuid, 'data' => ['message' => 'Test event from Coolify.'], 'occurred_at' => now()]);
            $delivery = EventDelivery::create(['platform_event_id' => $event->id, 'event_subscription_id' => $subscription->id, 'state' => EventDelivery::PENDING, 'next_attempt_at' => now()]);
            DeliverEventJob::dispatch($delivery->id);
            $this->dispatch('success', 'Test event queued.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.event-webhooks.index', [
            'subscriptions' => EventSubscription::query()->where('team_id', currentTeam()->id)->with(['deliveries' => fn ($query) => $query->with('event:id,type')->latest('id')->limit(5)])->orderBy('name')->get(),
            'typeOptions' => array_merge([['value' => '*', 'label' => 'All events']], array_map(fn (string $type) => ['value' => $type, 'label' => $type], array_values(array_diff(PlatformEvent::TYPES, ['ping'])))),
            'isAdmin' => (bool) auth()->user()?->isAdminOfTeam((int) currentTeam()->id),
        ]);
    }
}
