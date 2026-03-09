<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\MapNormalizer;

class MapNormalizerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MapNormalizer(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta')
        );
    }
}
