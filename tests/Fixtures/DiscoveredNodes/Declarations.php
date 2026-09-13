<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Fixtures\DiscoveredNodes;

/** Several declarations in one file, none of them a node. */
interface DiscoveredMarker
{
}

trait DiscoveredTrait
{
}

enum DiscoveredLevel: string
{
    case Low = 'low';
}

final class PlainHelper
{
}
