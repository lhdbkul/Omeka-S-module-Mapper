<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\Mapper;

class MapperFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $mapper = new Mapper(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Logger'),
            $services->get('Mapper\MapperConfig'),
            $services->get('MvcTranslator')
        );
        $mapper->setPreprocessor($services->get('Mapper\Preprocessor'));
        $mapper->setMappingPaths($services->get('Mapper\MappingPaths'));
        $mapper->setTwigXmlEngine($services->get('Mapper\TwigXmlEngine'));
        return $mapper;
    }
}
