<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ConfigurationContract;
use App\Models\Environment;
use App\Models\SharedEnvironmentVariable;
use App\Services\Configuration\ConfigurationReport;
use App\Services\Configuration\SharedVariableGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ConfigurationController extends Controller
{
    #[OA\Get(
        summary: 'Configuration report of an environment',
        description: 'Changes not deployed yet, shared variables changed after the last deployment, references to missing shared variables, contract violations and secrets due for rotation. Never contains values.',
        path: '/configuration/environments/{uuid}',
        operationId: 'get-environment-configuration-report',
        security: [['bearerAuth' => []]],
        tags: ['Configuration'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Environment UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rotation_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 180, minimum: 1, maximum: 3650)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Report.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function environment(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $environment = Environment::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->with('project')->first();
        if ($environment === null) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }
        $rotationDays = max(1, min(3650, (int) $request->query('rotation_days', ConfigurationReport::DEFAULT_ROTATION_DAYS)));

        return response()->json((new ConfigurationReport($rotationDays))->forEnvironment($environment));
    }

    #[OA\Get(
        summary: 'Rotation plan of a shared variable',
        description: 'Every resource that reads the shared variable and the steps to rotate it. Never contains the value.',
        path: '/configuration/shared-variables/{id}/rotation-plan',
        operationId: 'get-shared-variable-rotation-plan',
        security: [['bearerAuth' => []]],
        tags: ['Configuration'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Rotation plan.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function rotationPlan(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $variable = SharedEnvironmentVariable::query()->where('team_id', $teamId)->whereKey((int) $request->route('id'))->first();
        if ($variable === null) {
            return response()->json(['message' => 'Shared variable not found.'], 404);
        }

        return response()->json((new ConfigurationReport)->rotationPlan($variable));
    }

    #[OA\Get(
        summary: 'Get configuration contract',
        description: 'The environment variable keys the application declares as required or optional.',
        path: '/applications/{uuid}/configuration-contract',
        operationId: 'get-application-configuration-contract',
        security: [['bearerAuth' => []]],
        tags: ['Configuration'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Contract and its current check.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function contract(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($application === null) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        $contract = ConfigurationContract::query()->where('application_id', $application->id)->first();
        if ($contract === null) {
            return response()->json(['message' => 'The application has no configuration contract.'], 404);
        }

        return response()->json($this->serialize($contract, $application));
    }

    #[OA\Put(
        summary: 'Set configuration contract',
        description: 'Declare which environment variable keys the application needs, for example from the repository that builds it. Only keys are sent, never values.',
        path: '/applications/{uuid}/configuration-contract',
        operationId: 'put-application-configuration-contract',
        security: [['bearerAuth' => []]],
        tags: ['Configuration'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['required_keys'], properties: [
            new OA\Property(property: 'required_keys', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'optional_keys', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'source', type: 'string', maxLength: 255),
            new OA\Property(property: 'source_revision', type: 'string', maxLength: 255),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Contract stored.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function updateContract(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($application === null) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if ($request->user()->cannot('manageEnvironment', $application)) {
            return response()->json(['message' => 'You need at least admin permissions in this team.'], 403);
        }
        $keyRule = ['string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_.\-]*$/'];
        $validator = Validator::make($request->all(), [
            'required_keys' => 'present|array|max:'.ConfigurationContract::MAX_KEYS,
            'required_keys.*' => $keyRule,
            'optional_keys' => 'nullable|array|max:'.ConfigurationContract::MAX_KEYS,
            'optional_keys.*' => $keyRule,
            'source' => 'nullable|string|max:255',
            'source_revision' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $contract = ConfigurationContract::query()->updateOrCreate(['application_id' => $application->id], [
            'team_id' => $teamId,
            'required_keys' => array_values(array_unique($request->input('required_keys', []))),
            'optional_keys' => array_values(array_unique($request->input('optional_keys') ?? [])),
            'source' => $request->input('source'),
            'source_revision' => $request->input('source_revision'),
            'updated_by_user_id' => $request->user()->id,
        ]);
        auditLog('api.configuration_contract.updated', [
            'team_id' => $teamId,
            'application_uuid' => $application->uuid,
            'required_keys' => count($contract->requiredKeys()),
            'source' => $contract->source,
            'source_revision' => $contract->source_revision,
        ]);

        return response()->json($this->serialize($contract, $application));
    }

    #[OA\Delete(
        summary: 'Delete configuration contract',
        path: '/applications/{uuid}/configuration-contract',
        operationId: 'delete-application-configuration-contract',
        security: [['bearerAuth' => []]],
        tags: ['Configuration'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Contract deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deleteContract(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($application === null) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if ($request->user()->cannot('manageEnvironment', $application)) {
            return response()->json(['message' => 'You need at least admin permissions in this team.'], 403);
        }
        $deleted = ConfigurationContract::query()->where('application_id', $application->id)->delete();
        if ($deleted > 0) {
            auditLog('api.configuration_contract.deleted', ['team_id' => $teamId, 'application_uuid' => $application->uuid]);
        }

        return response()->json(['message' => $deleted > 0 ? 'Contract deleted.' : 'The application had no contract.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ConfigurationContract $contract, Application $application): array
    {
        $application->loadMissing('environment_variables');
        $report = new ConfigurationReport;
        $edges = (new SharedVariableGraph((int) $contract->team_id))->edges((int) $application->environment_id)->where('resource_uuid', $application->uuid);

        return [
            'application_uuid' => $application->uuid,
            'required_keys' => $contract->requiredKeys(),
            'optional_keys' => $contract->optionalKeys(),
            'source' => $contract->source,
            'source_revision' => $contract->source_revision,
            'updated_at' => $contract->updated_at?->toIso8601String(),
            'check' => $report->checkContract($contract, $application->environment_variables->where('is_preview', false), $edges),
        ];
    }
}
