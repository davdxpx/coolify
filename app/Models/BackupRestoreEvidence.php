<?php

namespace App\Models;

class BackupRestoreEvidence extends BaseModel
{
    protected $table = 'backup_restore_evidence';

    public const RESULTS = ['passed', 'failed'];

    public const METHODS = ['manual', 'automated'];

    protected $fillable = ['uuid', 'team_id', 'database_type', 'database_id', 'backup_execution_uuid', 'result', 'method', 'notes', 'verified_at', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'backup_execution_uuid' => $this->backup_execution_uuid,
            'result' => $this->result,
            'method' => $this->method,
            'notes' => $this->notes,
            'verified_at' => $this->verified_at->toIso8601String(),
        ];
    }
}
