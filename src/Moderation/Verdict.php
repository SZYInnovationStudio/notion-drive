<?php

declare(strict_types=1);

namespace NotionDrive\Moderation;

/**
 * 审核结论值对象。
 */
final class Verdict
{
    public const APPROVED = 'approved';
    public const FLAGGED  = 'flagged';
    public const PENDING  = 'pending';

    public function __construct(
        public readonly string $decision,
        public readonly string $reason = '',
        public readonly string $matched = '',
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly string $detail = ''
    ) {
    }

    public static function approved(string $provider = '', string $detail = ''): self
    {
        return new self(self::APPROVED, '', '', $provider, '', $detail);
    }

    public static function flagged(
        string $reason,
        string $matched = '',
        string $provider = '',
        string $model = '',
        string $detail = ''
    ): self {
        return new self(self::FLAGGED, $reason, $matched, $provider, $model, $detail);
    }

    public static function pending(
        string $reason,
        string $provider = '',
        string $model = '',
        string $detail = ''
    ): self {
        return new self(self::PENDING, $reason, '', $provider, $model, $detail);
    }

    public function isFlagged(): bool
    {
        return $this->decision === self::FLAGGED;
    }

    public function isPending(): bool
    {
        return $this->decision === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->decision === self::APPROVED;
    }
}
