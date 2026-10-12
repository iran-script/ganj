<?php

namespace App\Services\Scenarios;

class AuthoringNodeCatalog
{
    public const ROLES = 'SCANNER,NAVIGATOR,DECODER,AR_OPERATOR';

    public const AUTOMATIC = ['SetVariable', 'CheckVariable', 'Score', 'RoleCheck', 'AssignRole', 'RandomPlayer'];

    public function groups(): array
    {
        return ['شروع و پایان' => ['Start', 'Finish'], 'محتوا' => ['Message', 'Puzzle', 'MediaReveal'], 'مکان' => ['GPS / Location', 'QR'],
            'منطق' => ['Time', 'Condition', 'SetVariable', 'CheckVariable', 'Score'], 'نقش‌ها' => ['RoleCheck', 'AssignRole', 'RandomPlayer'],
            'تیمی' => ['SplitInformation', 'TeamTogether', 'Barrier', 'Relay'], 'کنترل زنده' => ['LiveChallenge'],
            'رسانه و مدرک' => ['PhotoSubmission', 'AudioSubmission', 'FileSubmission']];
    }

    public function describe(): array
    {
        $definitions = app(NodeTypeRegistry::class)->definitions();
        $nodes = [];
        foreach ($this->groups() as $category => $types) {
            foreach ($types as $type) {
                $definition = $definitions[$type];
                $nodes[] = ['type' => $type, 'category' => $category, 'config_schema' => $definition['rules'], 'runtime_handler' => $definition['runtime_handler'],
                    'builder_inspector' => $type, 'player_facing' => ! in_array($type, [...self::AUTOMATIC, 'Start', 'Condition'], true),
                    'outgoing_semantics' => $type === 'Time' ? ['SUCCESS', 'TIMEOUT', 'TRUE', 'FALSE'] : $this->outcomes($type, $type === 'Condition' ? ['operand' => 'score'] : []),
                    'supports_timeout' => isset($this->failureRules($type)['timeout_seconds'])];
            }
        }

        return $nodes;
    }

    /** Additional authoring types share the existing runtime domains. */
    public function rules(): array
    {
        $comparison = ['operator' => 'required|in:==,!=,>,>=,<,<=,EXISTS,NOT_EXISTS', 'value' => 'present'];
        $variable = ['variable' => 'required|string|regex:/^[A-Za-z][A-Za-z0-9_]{0,99}$/'];
        $team = ['minimum_player_count' => 'required|integer|min:1|max:8', 'all_active_players' => 'sometimes|boolean',
            'required_roles' => 'sometimes|array|list|max:4', 'required_roles.*' => 'string|distinct|in:'.self::ROLES,
            'synchronization_window_seconds' => 'sometimes|integer|min:1|max:86400', 'active_slot_only' => 'sometimes|boolean', 'support_policy' => 'sometimes|in:COLLABORATE,DENY'];
        $submission = ['instruction' => 'required|string|max:10000', 'max_size_mb' => 'required|integer|min:1|max:25',
            'allow_resubmit' => 'required|boolean', 'approval_mode' => 'required|in:AUTO_ACCEPT,GAMEMASTER_REVIEW',
            'success_message' => 'sometimes|string|max:2000', 'reject_message' => 'sometimes|string|max:2000'];

        return [
            'Time' => ['mode' => 'required|in:DELAY,TIMEOUT,TIME_WINDOW,ELAPSED_CHECK',
                'duration_seconds' => 'required_if:mode,DELAY,TIMEOUT|integer|min:1|max:86400',
                'starts_at' => 'required_if:mode,TIME_WINDOW|date_format:Y-m-d\\TH:i:sP',
                'ends_at' => 'required_if:mode,TIME_WINDOW|date_format:Y-m-d\\TH:i:sP|after:starts_at',
                'operator' => 'required_if:mode,ELAPSED_CHECK|in:==,!=,>,>=,<,<=',
                'value' => 'required_if:mode,ELAPSED_CHECK|integer|min:0|max:604800'],
            'SetVariable' => $variable + ['value' => 'present'],
            'CheckVariable' => $variable + $comparison,
            'Score' => ['operation' => 'required|in:ADD,SUBTRACT', 'amount' => 'required|integer|min:1|max:1000000', 'reason' => 'required|string|max:1000'],
            'RoleCheck' => ['role' => 'required|in:'.self::ROLES],
            'AssignRole' => ['roles' => 'required|array|list|min:1|max:4', 'roles.*' => 'string|distinct|in:'.self::ROLES,
                'assignment_policy' => 'required|in:LEAST_RECENTLY_USED,SEEDED_RANDOM'],
            'RandomPlayer' => $variable + ['private_text' => 'sometimes|string|max:10000'],
            'SplitInformation' => $team + ['role_content' => 'required|array:SCANNER,NAVIGATOR,DECODER,AR_OPERATOR',
                'role_content.*' => 'required|array:text', 'role_content.*.text' => 'required|string|max:10000'],
            'TeamTogether' => $team,
            'Barrier' => $team + ['required_variables' => 'sometimes|array|list|max:20', 'required_variables.*' => 'string|distinct|max:100'],
            'LiveChallenge' => ['category' => 'required|in:MESSAGE,TEAM_OBJECTIVE', 'instruction' => 'sometimes|string|max:10000'],
            'MediaReveal' => ['media_asset_id' => 'required|uuid', 'description' => 'sometimes|string|max:10000', 'cta_label' => 'sometimes|string|max:100', 'require_acknowledgement' => 'required|boolean'],
            'PhotoSubmission' => $submission + ['camera_policy' => 'required|in:CAMERA_ONLY,CAMERA_OR_GALLERY,CAMERA_OR_LIBRARY', 'require_location' => 'required|boolean', 'require_timestamp' => 'required|boolean'],
            'AudioSubmission' => $submission + ['max_duration_seconds' => 'required|integer|min:1|max:300', 'record_policy' => 'required|in:RECORD_ONLY,RECORD_OR_LIBRARY'],
            'FileSubmission' => $submission + ['allowed_mime_types' => 'required|array|list|min:1|max:6', 'allowed_mime_types.*' => 'string|distinct|in:application/pdf,image/jpeg,image/png,audio/wav,audio/x-wav,audio/mpeg'],
        ];
    }

    public function outcomes(string $type, array $config): array
    {
        if (in_array($type, ['CheckVariable', 'RoleCheck'], true) || ($type === 'Condition' && isset($config['operand'])) || ($type === 'Time' && ($config['mode'] ?? '') === 'ELAPSED_CHECK')) {
            return ['TRUE', 'FALSE'];
        }
        if ($type === 'Finish') {
            return [];
        }
        $outcomes = match ($type) {
            'Time' => match ($config['mode'] ?? '') {
                'TIMEOUT' => ['SUCCESS', 'TIMEOUT'], 'TIME_WINDOW' => ['SUCCESS', 'TIMEOUT'], default => ['SUCCESS']
            },
            'PhotoSubmission', 'AudioSubmission', 'FileSubmission' => ['APPROVED', 'REJECTED'],
            default => ['SUCCESS'],
        };
        if (isset($config['timeout_seconds'])) {
            $outcomes[] = 'TIMEOUT';
        }
        if (isset($config['max_attempts']) || ($config['allow_retry'] ?? true) === false) {
            $outcomes[] = 'FAILURE';
        }

        return array_values(array_unique($outcomes));
    }

    public function failureRules(string $type): array
    {
        if (! in_array($type, ['Message', 'GPS / Location', 'QR', 'Puzzle', 'MediaReveal', 'PhotoSubmission', 'AudioSubmission', 'FileSubmission', 'LiveChallenge', 'TeamTogether', 'Barrier', 'Relay', 'SplitInformation'], true)) {
            return [];
        }

        $rules = ['timeout_seconds' => 'sometimes|integer|min:1|max:86400', 'guidance_text' => 'sometimes|string|max:2000',
            'success_message' => 'sometimes|string|max:2000', 'invalid_message' => 'sometimes|string|max:2000'];
        if (in_array($type, ['Puzzle', 'QR', 'GPS / Location'], true)) {
            $rules += ['allow_retry' => 'sometimes|boolean', 'retry_delay' => 'sometimes|integer|min:0|max:3600', 'max_attempts' => 'sometimes|integer|min:1|max:1000'];
        }

        return $rules;
    }
}
