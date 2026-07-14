<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflow extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_approval_workflows';

    protected $fillable = [
        'code', 'name', 'module', 'trigger', 'steps', 'sla', 'escalation',
        'version_label', 'approval_status', 'status',
    ];
}
