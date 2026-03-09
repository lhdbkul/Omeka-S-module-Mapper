<?php declare(strict_types=1);

namespace Mapper\Service\ControllerPlugin;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Mvc\Controller\Plugin\MapperConfigList;

class MapperConfigListFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MapperConfigList(
            $services->get('Omeka\ApiManager'),
            $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')
        );
    }
}
