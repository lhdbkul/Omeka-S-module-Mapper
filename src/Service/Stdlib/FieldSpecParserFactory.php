<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\FieldSpecParser;
use Psr\Container\ContainerInterface;

class FieldSpecParserFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new FieldSpecParser(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta')
        );
    }
}
