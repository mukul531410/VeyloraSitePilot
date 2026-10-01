<?php

namespace App\Services;

use App\Exceptions\OperationRecoveryChainException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

class OperationRecoveryChain
{
    public function maximumLength(): int
    {
        $value = config('sitepilot.operations.max_recovery_chain_length');
        $maximum = is_int($value)
            ? $value
            : (is_string($value) && ctype_digit($value) ? (int) $value : 0);

        if ($maximum < 1) {
            throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_CONFIGURATION);
        }

        return $maximum;
    }

    public function length(Operation $operation): int
    {
        $maximum = $this->maximumLength();
        $length = 0;
        $visited = [];
        $siteId = (string) $operation->site_id;
        $current = Operation::query()->find($operation->getKey());
        if ($current === null) {
            throw new OperationRecoveryChainException(OperationRecoveryChainException::SOURCE_MISSING);
        }

        while ($current !== null) {
            $id = (string) $current->getKey();
            if (isset($visited[$id]) || (string) $current->site_id !== $siteId) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_LINEAGE);
            }

            $visited[$id] = true;
            $length++;
            if ($length > $maximum) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_LINEAGE);
            }

            $parentId = $current->recovery_of_operation_id;
            if ($parentId === null) {
                break;
            }

            $current = Operation::query()->find($parentId);
            if ($current === null) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_LINEAGE);
            }
        }

        return $length;
    }

    /**
     * Run the caller's work only after a dead-letter source and its complete
     * same-site lineage are locked and confirmed to have room for a successor.
     */
    public function withLockedSource(Operation $source, callable $callback): mixed
    {
        return DB::transaction(function () use ($source, $callback) {
            $maximum = $this->maximumLength();
            if (! $source->exists || $source->getKey() === null) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::SOURCE_MISSING);
            }

            $lockedSource = Operation::query()->lockForUpdate()->find($source->getKey());
            if ($lockedSource === null) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::SOURCE_MISSING);
            }

            if ($lockedSource->status !== Operation::STATUS_DEAD_LETTER) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::SOURCE_INELIGIBLE);
            }

            $siteId = (string) $lockedSource->site_id;
            $cursor = $lockedSource;
            $visited = [];
            $chainLength = 0;

            // Lock in one consistent order: source, then each ancestor toward root.
            while ($cursor !== null) {
                $id = (string) $cursor->getKey();
                if (isset($visited[$id]) || (string) $cursor->site_id !== $siteId) {
                    throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_LINEAGE);
                }

                $visited[$id] = true;
                $chainLength++;
                if ($chainLength > $maximum) {
                    throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_LINEAGE);
                }

                $parentId = $cursor->recovery_of_operation_id;
                if ($parentId === null) {
                    break;
                }

                $cursor = Operation::query()->lockForUpdate()->find($parentId);
                if ($cursor === null) {
                    throw new OperationRecoveryChainException(OperationRecoveryChainException::INVALID_LINEAGE);
                }
            }

            if ($chainLength >= $maximum) {
                throw new OperationRecoveryChainException(OperationRecoveryChainException::LIMIT_EXCEEDED);
            }

            return $callback($lockedSource, $chainLength);
        });
    }
}
