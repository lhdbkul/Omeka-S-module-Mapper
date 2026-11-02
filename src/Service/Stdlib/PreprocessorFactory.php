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
        $logger = $services->get('Omeka\Logger');
        $processXslt = $services->get(ProcessXslt::class);
        $api = $services->get('Omeka\ApiManager');

        // Base path for module mapping files.
        $basePath = dirname(__DIR__, 3) . '/data/mapping';

        // User base path for user mapping files (files/mapping/).
        $userBasePath = ($services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')) . '/mapping';

        return new Preprocessor($logger, $processXslt, $api, $basePath, $userBasePath);
    }
}
