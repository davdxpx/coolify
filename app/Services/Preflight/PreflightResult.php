<?php

namespace App\Services\Preflight;

use App\Enums\PreflightState;

final class PreflightResult
{
    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly PreflightState $state,
        public readonly string $summary,
        public readonly bool $required = true,
        public readonly bool $overridable = false,
        public readonly ?string $remediation = null,
        public readonly array $details = [],
        public readonly string $category = 'general',
        public readonly int $definitionVersion = 1,
    ) {}

    public function blocks(): bool
    {
        return $this->required && in_array($this->state, [PreflightState::FAILED, PreflightState::UNKNOWN], true);
    }

    public function blocksUnless(array $acknowledged): bool
    {
        return $this->blocks() && ! ($this->overridable && in_array($this->id, $acknowledged, true));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'category' => $this->category,
            'state' => $this->state->value,
            'summary' => $this->summary,
            'required' => $this->required,
            'overridable' => $this->overridable,
            'remediation' => $this->remediation,
            'details' => $this->details,
            'definition_version' => $this->definitionVersion,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? 'unknown'),
            label: (string) ($data['label'] ?? 'Unknown check'),
            state: PreflightState::tryFrom((string) ($data['state'] ?? '')) ?? PreflightState::UNKNOWN,
            summary: (string) ($data['summary'] ?? ''),
            required: (bool) ($data['required'] ?? true),
            overridable: (bool) ($data['overridable'] ?? false),
            remediation: isset($data['remediation']) ? (string) $data['remediation'] : null,
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
            category: (string) ($data['category'] ?? 'general'),
            definitionVersion: (int) ($data['definition_version'] ?? 1),
        );
    }

    /**
     * @param  iterable<PreflightResult>  $results
     * @param  list<string>  $acknowledged
     * @return list<PreflightResult>
     */
    public static function blocking(iterable $results, array $acknowledged = []): array
    {
        $blocking = [];
        foreach ($results as $result) {
            if ($result->blocksUnless($acknowledged)) {
                $blocking[] = $result;
            }
        }

        return $blocking;
    }
}
