<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\MappingPaths;
use Psr\Container\ContainerInterface;

class MappingPathsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MappingPaths(
            dirname(__DIR__, 3) . '/data/mapping',
            ($services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')) . '/mapping'
        );
    }
}
