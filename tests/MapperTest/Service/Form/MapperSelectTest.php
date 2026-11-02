<?php declare(strict_types=1);

namespace MapperTest\Service\Form;

use Mapper\Form\Element\MapperSelect;
use MapperTest\MapperDbTestCase;

/**
 * Options of the element to select a mapping.
 */
class MapperSelectTest extends MapperDbTestCase
{
    public function testFileMappingsAreListed(): void
    {
        $element = $this->getServiceLocator()->get('FormElementManager')
            ->get(MapperSelect::class, ['disable_group_by_owner' => true]);

        $fileMappings = [];
        foreach ($element->getValueOptions() as $option) {
            if (is_array($option) && ($option['label'] ?? null) === 'File mappings') {
                $fileMappings = $option['options'];
            }
        }
        $this->assertArrayHasKey('module:lido/lido.mc.xml', $fileMappings);
    }
}
