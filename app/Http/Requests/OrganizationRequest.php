<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Services\Organizations\OrganizationInput;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class OrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'index' || $action === 'create') {
            Gate::forUser($this->user())->authorize($action === 'index' ? 'viewAny' : 'create', Organization::class);
        } else {
            $context = app(OrganizationScope::class)->resolve($this->user(), $this->route('organization_id'));
            $context->authorize(match ($action) {
                'show' => 'view',
                'update' => 'update',
                default => 'manageMembers',
            });
        }

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'adminAddMember' => OrganizationInput::rules('addMember') + ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            'adminRemoveMember' => ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            'changeMemberRole' => OrganizationInput::rules('changeRole'),
            default => OrganizationInput::rules($this->route()->getActionMethod()),
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                $action = match ($this->route()->getActionMethod()) {
                    'adminAddMember' => 'addMember', 'adminRemoveMember' => 'removeMember', 'changeMemberRole' => 'changeRole', default => $this->route()->getActionMethod(),
                };
                OrganizationInput::validate($this->all(), $action);
            }
        }];
    }
}
