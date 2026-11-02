<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\Preprocessor;
use Mapper\Stdlib\ProcessXslt;

class PreprocessorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Preprocessor(
            $services->get('Omeka\Logger'),
            $services->get(ProcessXslt::class),
            $services->get('Mapper\MappingPaths'),
            $services->get('Omeka\ApiManager')
        );
    }
}
