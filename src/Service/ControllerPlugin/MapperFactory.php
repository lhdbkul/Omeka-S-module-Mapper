<?php declare(strict_types=1);

namespace Mapper\Service\ControllerPlugin;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Mvc\Controller\Plugin\Mapper;

class MapperFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Mapper(
            $services->get('Mapper\Mapper'),
            $services->get('Mapper\MapperConfig')
        );
    }
}
