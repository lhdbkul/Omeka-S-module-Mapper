<?php declare(strict_types=1);

namespace Mapper\Service\Form\Element;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Form\Element\MapperSelect;

class MapperSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $element = new MapperSelect(null, $options ?? []);
        $element
            ->setMapperConfigList($services->get('ControllerPluginManager')->get('mapperConfigList'))
            ->setApiManager($services->get('Omeka\ApiManager'));
        return $element;
    }
}
