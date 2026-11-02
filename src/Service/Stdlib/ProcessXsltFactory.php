<?php declare(strict_types=1);

namespace Mapper\Service\Stdlib;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Mapper\Module;
use Mapper\Stdlib\ProcessXslt;

class ProcessXsltFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $logger = $services->get('Omeka\Logger');

        // External XSLT processor command (generally Saxon for XSLT 2.0/3.0).
        // Mode controls source: auto-detect, custom command, or disabled (PHP
        // internal xslt 1 fallback).
        $settings = $services->get('Omeka\Settings');
        $mode = $settings->get('mapper_xslt_processor_mode', 'auto');

        if ($mode === 'disabled') {
            $command = null;
        } elseif ($mode === 'custom') {
            $command = $settings->get('mapper_xslt_processor') ?: null;
        } else {
            $command = Module::detectXsltCommand();
        }

        $config = $services->get('Config');
        $tempDir = $config['temp_dir'] ?? sys_get_temp_dir();

        // The limits are set in the config form, in seconds and megabytes. An
        // empty value uses the default one.
        $limit = fn (string $name, int $default): int => is_numeric($value = $settings->get($name)) ? (int) $value : $default;
        return (new ProcessXslt($logger, $command, $tempDir))
            ->setTimeout($limit('mapper_xslt_timeout', 600))
            ->setDownloadLimits(
                $limit('mapper_download_max_size', 512) * 1048576,
                $limit('mapper_download_timeout', 60)
            );
    }
}
