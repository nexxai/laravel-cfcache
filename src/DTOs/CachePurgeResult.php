<?php

namespace JTSmith\Cloudflare\DTOs;

readonly class CachePurgeResult
{
    public function __construct(
        public string $id,
        public string $message
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            message: $data['message'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
        ];
    }
}
