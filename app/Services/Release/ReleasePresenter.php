<?php

namespace App\Services\Release;

use App\Models\Application;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\ReleaseGroupMember;
use App\Models\ReleaseMember;

/**
 * The public shape of release groups and releases, shared by the API and the MCP tools.
 */
class ReleasePresenter
{
    /**
     * @param  iterable<ReleaseGroupMember|ReleaseMember>  $members
     * @return Collection<int, Application>
     */
    public static function applicationsFor(iterable $members)
    {
        $ids = collect($members)->where('resource_type', Application::class)->pluck('resource_id')->unique()->values();

        return Application::query()->whereIn('id', $ids)->get(['id', 'uuid', 'name'])->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public static function group(ReleaseGroup $group, $applications): array
    {
        $latest = $group->releases->first();

        return [
            'uuid' => $group->uuid,
            'name' => $group->name,
            'description' => $group->description,
            'project_uuid' => $group->environment?->project?->uuid,
            'environment_uuid' => $group->environment?->uuid,
            'environment_name' => $group->environment?->name,
            'is_enabled' => $group->is_enabled,
            'failure_policy' => $group->failure_policy->value,
            'max_parallel' => $group->max_parallel,
            'require_healthy' => $group->require_healthy,
            'verification_timeout_seconds' => $group->verification_timeout_seconds,
            'members' => $group->members->map(fn (ReleaseGroupMember $member) => [
                'uuid' => $member->uuid,
                'resource_type' => 'application',
                'resource_uuid' => $applications->get($member->resource_id)?->uuid,
                'resource_name' => $applications->get($member->resource_id)?->name,
                'phase' => $member->phase,
                'is_required' => $member->is_required,
                'is_enabled' => $member->is_enabled,
                'force_rebuild' => $member->force_rebuild,
                'depends_on' => $member->dependencies(),
            ])->values(),
            'latest_release' => $latest ? [
                'uuid' => $latest->uuid,
                'state' => $latest->state->value,
                'created_at' => $latest->created_at?->toIso8601String(),
                'finished_at' => $latest->finished_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function release(Release $release, $applications): array
    {
        return [
            'uuid' => $release->uuid,
            'release_group_uuid' => $release->group?->uuid,
            'state' => $release->state->value,
            'trigger' => $release->trigger,
            'correlation_id' => $release->correlation_id,
            'requested_commit' => $release->requested_commit,
            'notes' => $release->notes,
            'failure_policy' => $release->failurePolicy()->value,
            'failure_summary' => $release->failure_summary,
            'attention_reason' => $release->attention_reason,
            'preflight' => $release->preflight ?? [],
            'acknowledged_checks' => $release->acknowledgedChecks(),
            'acknowledgement_reason' => $release->acknowledgement_reason,
            'summary' => $release->summary,
            'created_at' => $release->created_at?->toIso8601String(),
            'started_at' => $release->started_at?->toIso8601String(),
            'finished_at' => $release->finished_at?->toIso8601String(),
            'cancelled_at' => $release->cancelled_at?->toIso8601String(),
            'members' => $release->members->map(fn (ReleaseMember $member) => [
                'uuid' => $member->uuid,
                'resource_type' => 'application',
                'resource_uuid' => $applications->get($member->resource_id)?->uuid,
                'resource_name' => $member->resource_name,
                'phase' => $member->phase,
                'is_required' => $member->is_required,
                'depends_on' => $member->dependencies(),
                'state' => $member->state->value,
                'deployment_uuid' => $member->deployment_uuid,
                'deployment_status' => $member->deployment_status,
                'requested_commit' => $member->requested_commit,
                'actual_commit' => $member->actual_commit,
                'image_digest' => $member->image_digest,
                'failure_reason' => $member->failure_reason,
                'note' => $member->note,
                'attempts' => $member->attempts,
                'verification' => $member->verification,
                'started_at' => $member->started_at?->toIso8601String(),
                'finished_at' => $member->finished_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
