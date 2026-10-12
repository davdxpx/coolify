<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ReleaseGroup;
use App\Services\Release\ReleasePresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListReleases extends Tool
{
    protected string $name = 'list_releases';

    protected string $description = 'Without release_group_uuid: release groups of the team with members and latest release. With release_group_uuid: the latest releases of that group with the state of every member.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }
        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $groupUuid = $request->get('release_group_uuid');
        if (is_string($groupUuid) && $groupUuid !== '') {
            $group = ReleaseGroup::ownedByTeam((int) $teamId)->where('uuid', $groupUuid)->first();
            if ($group === null) {
                return $this->mcpError($request, "Release group [{$groupUuid}] not found.", ['resource_uuid' => $groupUuid]);
            }
            $limit = max(1, min(50, (int) ($request->get('limit') ?? 10)));
            $releases = $group->releases()->with(['members', 'group'])->limit($limit)->get();
            $applications = ReleasePresenter::applicationsFor($releases->flatMap->members);

            return $this->mcpSuccess($request, $this->respond($releases->map(fn ($release) => ReleasePresenter::release($release, $applications))->values()->all()));
        }

        $groups = ReleaseGroup::ownedByTeam((int) $teamId)->with(['environment.project', 'members', 'releases' => fn ($query) => $query->limit(1)])->orderBy('name')->get();
        $applications = ReleasePresenter::applicationsFor($groups->flatMap->members);

        return $this->mcpSuccess($request, $this->respond($groups->map(fn ($group) => ReleasePresenter::group($group, $applications))->values()->all(), [
            ['tool' => 'get_release', 'reason' => 'Details of one release'],
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'release_group_uuid' => $schema->string()->description('Optional release group UUID to list its releases.'),
            'limit' => $schema->integer()->description('Releases per group (default 10, max 50).'),
        ];
    }
}
