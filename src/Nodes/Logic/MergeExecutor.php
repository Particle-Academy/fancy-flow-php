<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `merge` — combine the incoming port values into one result. `mode: merge`
 * (default) object-merges associative inputs and keeps lists/scalars under their
 * port key; `mode: concat` flattens every input into a single list. Null inputs
 * (dead branches) are ignored, so `merge` composes cleanly after a decision.
 */
final class MergeExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $mode = (string) $ctx->option('mode', 'merge');

        if ($mode === 'concat') {
            $out = [];
            foreach ($ctx->inputs as $value) {
                if ($value === null) {
                    continue;
                }
                if (is_array($value) && array_is_list($value)) {
                    foreach ($value as $item) {
                        $out[] = $item;
                    }
                } else {
                    $out[] = $value;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($ctx->inputs as $port => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value) && ! array_is_list($value)) {
                $out = array_merge($out, $value);
            } else {
                $out[$port] = $value;
            }
        }

        return $out;
    }
}
