<?php

namespace Toshkq93\Outbox\Idempotency;

enum AttemptStatus: string
{
    /** The key is ours: run the request, then complete() or release(). */
    case Acquired = 'acquired';

    /** Another request with this key is still running. */
    case InProgress = 'in_progress';

    /** A request with this key finished; its response can be replayed. */
    case Completed = 'completed';
}
