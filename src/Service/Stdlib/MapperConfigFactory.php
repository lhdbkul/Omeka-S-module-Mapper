<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\FieldSpecParser;
use Mapper\Stdlib\MapperConfig;
use Mapper\Stdlib\PatternParser;

class MapperConfigFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MapperConfig(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Logger'),
            $services->get('Mapper\MappingPaths'),
            $services->get(FieldSpecParser::class),
            $services->get(PatternParser::class)
        );
    }
}
