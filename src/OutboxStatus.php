<?php

namespace Toshkq93\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Dead = 'dead';
}
