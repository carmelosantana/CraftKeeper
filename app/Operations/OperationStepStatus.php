<?php

namespace App\Operations;

/**
 * The status of a single step within an operation's execution. Distinct
 * from OperationStatus (which tracks the operation as a whole) so a
 * handler can report fine-grained progress (e.g. "download" succeeded
 * while "verify" is still running) without inventing new operation-level
 * states.
 */
enum OperationStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
