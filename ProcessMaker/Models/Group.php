<?php

namespace ProcessMaker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Spatie\BinaryUuid\HasBinaryUuid;

/**
 * Represents a group definition.
 *
 * @property string $uuid
 * @property string $name
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon $created_at
 *
 */
class Group extends Model
{
    use HasBinaryUuid;

    protected $fillable = [
        'name',
    ];

    public static function rules($existing = null)
    {
        $rules = [
            'name' => 'required|string|unique:groups,name'
        ];

        if ($existing) {
            $rules['name'] = [
                'required',
                'string',
                Rule::unique('groups')->ignore($existing->uuid)
            ];
        }

        return $rules;
    }

    public function permissionAssignments()
    {
        return $this->morphMany(PermissionAssignment::class, 'assignable', null, 'assignable_uuid');
    }

    public function groupMembersFromMemberable()
    {
        return $this->morphMany(GroupMember::class, 'member', null, 'member_uuid');
    }
    
    public function groupMembers()
    {
        return $this->hasMany(GroupMember::class);
    }

    public function permissions()
    {
        $permissions = [];
        foreach ($this->groupMembersFromMemberable as $gm) {
            $group = $gm->group;
            $permissions =
                array_merge($permissions, $group->permissions());
        }
        foreach ($this->permissionAssignments as $pa) {
            $permissions[] = $pa->permission;
        }
        return $permissions;
    }
}
