<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Release;
use App\Services\Release\ReleasePresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetRelease extends Tool
{
    protected string $name = 'get_release';

    protected string $description = 'One release: state, checks before the release (preflight), overrides, and per member the deployment, commit, image digest, verification and failure reason.';

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
        $uuid = $request->get('release_uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'release_uuid argument is required.');
        }
        $release = Release::query()->where('team_id', $teamId)->where('uuid', $uuid)->with(['members', 'group'])->first();
        if ($release === null) {
            return $this->mcpError($request, "Release [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        return $this->mcpSuccess($request, $this->respond(ReleasePresenter::release($release, ReleasePresenter::applicationsFor($release->members)), [
            ['tool' => 'get_deployment', 'reason' => 'Details of a member deployment'],
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'release_uuid' => $schema->string()->description('Release UUID.')->required(),
        ];
    }
}
