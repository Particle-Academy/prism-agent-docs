<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

enum Outcome: string
{
    /** A draft passed its checks and is in the store's draft slot. */
    case Ready = 'ready';

    /** Something asked for cannot be done in this format; nothing was drafted. */
    case Limit = 'limit';

    /** No draft passed its checks; nothing was saved. */
    case Failed = 'failed';

    /** The run ended without a draft and without a reason; nothing was saved. */
    case NoChange = 'no_change';
}
