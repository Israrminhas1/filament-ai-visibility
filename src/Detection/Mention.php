<?php

namespace IsrarMinhas\FilamentAiVisibility\Detection;

final class Mention
{
    public function __construct(
        /** "brand" or "competitor" */
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly string $nameMatched,
        /** Byte offset of the first mention, in the answer after Text::clean(). */
        public readonly int $offset,
        public readonly int $count,
        public readonly string $snippet,
        /** 1 = named first among all tracked subjects. Set by the detector. */
        public int $position = 0,
    ) {}
}
