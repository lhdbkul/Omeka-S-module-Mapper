<?php declare(strict_types=1);

namespace Mapper\Form\Element;

use Mapper\Mvc\Controller\Plugin\MapperConfigList;
use Omeka\Form\Element\AbstractGroupByOwnerSelect;

class MapperSelect extends AbstractGroupByOwnerSelect
{
    /**
     * @var MapperConfigList
     */
    protected $mapperConfigList;

    public function getResourceName()
    {
        return 'mappers';
    }

    public function getValueLabel($resource)
    {
        return $resource->label();
    }

    public function setMapperConfigList(MapperConfigList $mapperConfigList): self
    {
        $this->mapperConfigList = $mapperConfigList;
        return $this;
    }

    public function getValueOptions(): array
    {
        // Get database mappings from parent.
        $valueOptions = parent::getValueOptions();

        // Add file-based mappings if option is enabled.
        if (!$this->getOption('exclude_files')) {
            $fileMappings = $this->getFileMappings();
            if (!empty($fileMappings)) {
                // Wrap database mappings in a group when files are shown.
                if (!empty($valueOptions)) {
                    $dbMappings = [];
                    foreach ($valueOptions as $key => $option) {
                        if (is_array($option) && isset($option['label'], $option['options'])) {
                            // Already a group (e.g., grouped by owner).
                            $dbMappings[] = $option;
                        } else {
                            // Simple key => value.
                            $dbMappings[$key] = $option;
                        }
                    }
                    $valueOptions = [
                        [
                            'label' => 'Mappings', // @translate
                            'options' => $dbMappings,
                        ],
                    ];
                }
                // Add file mappings as a separate group.
                $valueOptions[] = [
                    'label' => 'File mappings', // @translate
                    'options' => $fileMappings,
                ];
            }
        }

        return $valueOptions;
    }

    /**
     * Get the mappings stored as files in the module and in files/mapping.
     *
     * @return array Associative array of file reference => label.
     */
    protected function getFileMappings(): array
    {
        if (!$this->mapperConfigList) {
            return [];
        }
        $list = $this->mapperConfigList->listMappings([
            ['*' => 'ini'],
            ['*' => 'xml'],
            ['*' => 'json'],
        ]);
        return $list['user']['options'] + $list['module']['options'];
    }
}
