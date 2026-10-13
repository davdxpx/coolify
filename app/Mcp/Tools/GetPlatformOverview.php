<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Services\Platform\PlatformOverview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetPlatformOverview extends Tool
{
    protected string $name = 'get_platform_overview';

    protected string $description = 'Release and operations view of the team: build capacity per server, deployment counts, active and recent releases, deployment freezes, database backup freshness, event webhook health. With metrics_hours, adds deployment and release metrics for that window.';

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

        $overview = new PlatformOverview((int) $teamId);
        $data = $overview->overview();
        $hours = $request->get('metrics_hours');
        if ($hours !== null) {
            $data['metrics'] = $overview->metrics(max(1, min(720, (int) $hours)));
        }

        return $this->mcpSuccess($request, $this->respond($data, [
            ['tool' => 'list_releases', 'reason' => 'Release groups and their latest releases'],
            ['tool' => 'get_configuration_report', 'reason' => 'Undeployed configuration of an environment'],
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'metrics_hours' => $schema->integer()->description('Optional window in hours (1-720) for deployment and release metrics.'),
        ];
    }
}
