<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Check;

/** Why a draft was refused. `code` is stable; `message` is written for the model, which has to fix it. */
final readonly class Problem
{
    public const string UNREADABLE = 'unreadable';

    public const string EMPTY_DOCUMENT = 'empty_document';

    public const string PICTURE_MISSING = 'picture_missing';

    public const string BAND_CHANGED = 'band_changed';

    public const string NO_CHANGE = 'no_change';

    public const string INVALID = 'invalid';

    public function __construct(
        public string $code,
        public string $where,
        public string $message,
    ) {}

    /** @return array{code: string, where: string, message: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'where' => $this->where, 'message' => $this->message];
    }
}
