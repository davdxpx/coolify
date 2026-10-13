<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Services\Configuration\ConfigurationReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetConfigurationReport extends Tool
{
    protected string $name = 'get_configuration_report';

    protected string $description = 'Configuration of an environment without any value: applications with undeployed changes, shared variables changed after the last deployment, references to missing shared variables, contract violations and secrets due for rotation.';

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
        $uuid = $request->get('environment_uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'environment_uuid argument is required.');
        }
        $environment = Environment::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $uuid)->with('project')->first();
        if ($environment === null) {
            return $this->mcpError($request, "Environment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        return $this->mcpSuccess($request, $this->respond((new ConfigurationReport)->forEnvironment($environment)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'environment_uuid' => $schema->string()->description('Environment UUID.')->required(),
        ];
    }
}
