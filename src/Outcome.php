<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

enum Outcome: string
{
    /**
     * A draft passed its checks and is in the store's draft slot. It may come
     * WITH limits (DocsResult::$limits): a partly doable request gets the
     * doable part drafted and the rest offered, and the message says both.
     */
    case Ready = 'ready';

    /** Something asked for cannot be done in this format, and nothing that can be done was asked for; nothing was drafted. */
    case Limit = 'limit';

    /** No draft passed its checks; nothing was saved. */
    case Failed = 'failed';

    /** The run ended without a draft and without a reason; nothing was saved. */
    case NoChange = 'no_change';
}
