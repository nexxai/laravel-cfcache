<?php

namespace JTSmith\Cloudflare\DTOs;

readonly class WafRuleResult
{
    public function __construct(
        public string $action,
        public string $ruleId,
        public string $filterId,
        public string $expression,
        public string $message
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            action: $data['action'] ?? '',
            ruleId: $data['rule_id'] ?? '',
            filterId: $data['filter_id'] ?? '',
            expression: $data['expression'] ?? '',
            message: $data['message'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'rule_id' => $this->ruleId,
            'filter_id' => $this->filterId,
            'expression' => $this->expression,
            'message' => $this->message,
        ];
    }
}
