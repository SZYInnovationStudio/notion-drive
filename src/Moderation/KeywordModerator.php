<?php

declare(strict_types=1);

namespace NotionDrive\Moderation;

/**
 * 关键词审核器：文件名 + 文本内容命中关键词即拒绝。
 */
final class KeywordModerator implements Moderator
{
    /** @var string[] */
    private array $keywords;

    /**
     * @param string[] $keywords
     */
    public function __construct(array $keywords)
    {
        $this->keywords = array_values(array_filter(
            array_map(static fn ($word): string => mb_strtolower(trim((string) $word)), $keywords),
            static fn (string $word): bool => $word !== ''
        ));
    }

    public function name(): string
    {
        return 'keyword';
    }

    public function hasKeywords(): bool
    {
        return $this->keywords !== [];
    }

    public function review(array $subject): Verdict
    {
        if ($this->keywords === []) {
            return Verdict::approved($this->name(), 'skipped');
        }

        $haystack = mb_strtolower(trim(
            (string) ($subject['filename'] ?? '') . "\n" . (string) ($subject['text'] ?? '')
        ));

        if ($haystack === '') {
            return Verdict::approved($this->name(), 'skipped');
        }

        foreach ($this->keywords as $keyword) {
            if (mb_strpos($haystack, $keyword) !== false) {
                return Verdict::flagged(
                    '命中关键词：' . $keyword,
                    $keyword,
                    $this->name()
                );
            }
        }

        return Verdict::approved($this->name());
    }
}
