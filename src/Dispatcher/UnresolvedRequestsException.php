<?php

declare(strict_types=1);

namespace App\Dispatcher;

/**
 * Thrown by Dispatcher::run() when one or more requests can never receive a
 * response because every worker that could have answered them died.
 */
final class UnresolvedRequestsException extends \RuntimeException
{
    /** @param list<string> $requestIds */
    public function __construct(
        public readonly array $requestIds,
    ) {
        parent::__construct(sprintf(
            '%d request(s) will never receive a response, all workers able to serve them died: %s',
            count($this->requestIds),
            implode(', ', $this->requestIds)
        ));
    }
}
