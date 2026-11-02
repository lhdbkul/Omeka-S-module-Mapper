<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Stdlib\AutomapFields;

class AutomapFieldsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $easyMeta = $services->get('Common\EasyMeta');
        $fieldSpecParser = $services->get('Mapper\FieldSpecParser');
        $translator = $services->get('MvcTranslator');
        $logger = $services->get('Omeka\Logger');

        // Load default field mappings from config if available.
        $config = $services->get('Config');
        $map = $config['mapper']['field_automap'] ?? [];

        return new AutomapFields(
            $api,
            $easyMeta,
            $fieldSpecParser,
            $translator,
            $logger,
            $map
        );
    }
}
