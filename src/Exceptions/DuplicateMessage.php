<?php

namespace Toshkq93\Outbox\Exceptions;

use RuntimeException;

/**
 * @internal thrown out of Inbox::once()'s transaction to roll it back; never escapes once()
 */
final class DuplicateMessage extends RuntimeException implements OutboxException {}
