<?php declare(strict_types=1);

namespace Mapper\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Mapper\Api\Representation\MapperRepresentation;
use Mapper\Form\MappingDeleteForm;
use Mapper\Form\MappingForm;
use Mapper\Stdlib\MapperConfig;

class IndexController extends AbstractActionController
{
    /**
     * @var MapperConfig
     */
    protected $mapperConfig;

    public function __construct(MapperConfig $mapperConfig)
    {
        $this->mapperConfig = $mapperConfig;
    }

    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('label', 'asc');

        $response = $this->api()->search('mappers', [
            'sort_by' => 'label',
            'sort_order' => 'asc',
        ]);
        $mappings = $response->getContent();

        $internalMappings = $this->mapperConfigList()->getInternalMappings();

        return new ViewModel([
            'mappings' => $mappings,
            'resources' => $mappings,
            'internalMappings' => $internalMappings,
        ]);
    }

    public function showAction()
    {
        $entity = $this->getMapper();

        $isDbMapping = $entity instanceof MapperRepresentation;
        $isInternalMapping = is_string($entity);

        if (!$isDbMapping && !$isInternalMapping) {
            return $entity;
        }

        if ($isDbMapping) {
            return new ViewModel([
                'isDbMapping' => true,
                'mapping' => $entity,
                'resource' => $entity,
                'label' => $entity->label(),
                'content' => $entity->mapping(),
            ]);
        }

        $internalMappings = $this->mapperConfigList()->getInternalMappings();
        return new ViewModel([
            'isDbMapping' => false,
            'mapping' => null,
            'resource' => null,
            'label' => $internalMappings[$entity] ?? $entity,
            'content' => $this->mapperConfigList()->getMappingFromFile($entity),
        ]);
    }

    public function addAction()
    {
        return $this->editAction('add');
    }

    public function copyAction()
    {
        return $this->editAction('copy');
    }

    public function editAction(?string $action = null)
    {
        $action = $action ?? 'edit';

        if ($action === 'add') {
            $entity = null;
            $isDbMapping = false;
            $isInternalMapping = false;
        } else {
            $entity = $this->getMapper();
            $isDbMapping = $entity instanceof MapperRepresentation;
            $isInternalMapping = is_string($entity);
            if (!$isDbMapping && !$isInternalMapping) {
                return $entity;
            }
        }

        /** @var \Mapper\Form\MappingForm $form */
        $form = $this->getForm(MappingForm::class);

        if ($entity) {
            if ($isInternalMapping) {
                $internalMappings = $this->mapperConfigList()->getInternalMappings();
                $label = $internalMappings[$entity] ?? $entity;
                if ($action === 'copy') {
                    $label = sprintf($this->translate('%s (copy)'), $this->cleanLabel($label));
                }
                $form->setData([
                    'o:label' => $label,
                    'o-module-mapper:mapping' => $this->mapperConfigList()->getMappingFromFile($entity),
                ]);
            } else {
                $data = $entity->getJsonLd();
                if ($action === 'copy') {
                    $data['o:label'] = sprintf($this->translate('%s (copy)'), $data['o:label']);
                }
                $form->setData($data);
            }
        }

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                if ($isDbMapping && $entity && $action === 'edit') {
                    $response = $this->api($form)->update('mappers', $this->params('id'), $data, [], ['isPartial' => true]);
                } else {
                    $response = $this->api($form)->create('mappers', $data);
                }

                if ($response) {
                    $this->messenger()->addSuccess('Mapping successfully saved'); // @translate
                    return $this->redirect()->toRoute('admin/mapper/default', ['action' => 'browse'], true);
                }
                $this->messenger()->addError('Save of mapping failed'); // @translate
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $viewModel = new ViewModel([
            'form' => $form,
            'mapping' => $entity,
            'resource' => $entity,
        ]);

        if ($action === 'copy') {
            $viewModel->setTemplate('mapper/admin/index/add');
        }

        return $viewModel;
    }

    public function deleteAction()
    {
        /**
         * @var \Mapper\Api\Representation\MapperRepresentation $entity
         */

        $id = (int) $this->params()->fromRoute('id');

        try {
            // Don't use searchOne for performance and simplicity.
            $entity = $id ? $this->api()->read('mappers', ['id' => $id])->getContent() : null;
        } catch (\Throwable $e) {
            $entity = null;
        }

        if (!$entity) {
            $message = new PsrMessage('Mapping #{mapping_id} does not exist', ['mapping_id' => $id]);
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/mapper/default', ['action' => 'browse']);
        }

        // TODO Add a check to indicate if the mapping is used. See importer.

        $form = $this->getForm(MappingDeleteForm::class);
        $form->setData($entity->getJsonLd());

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $response = $this->api($form)->delete('mappers', $id);
                if ($response) {
                    $this->messenger()->addSuccess('Mapping successfully deleted'); // @translate
                } else {
                    $this->messenger()->addError('Delete of mapping failed'); // @translate
                }
                return $this->redirect()->toRoute('admin/mapper/default', ['action' => 'browse']);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'resource' => $entity,
            'mapping' => $entity,
            'form' => $form,
        ]);
    }

    /**
     * Get the current mapper from route params.
     *
     * The mapper may be an object or an id for internal mapping.
     *
     * @return MapperRepresentation|string|\Laminas\Http\Response
     */
    protected function getMapper()
    {
        $id = ((int) $this->params()->fromRoute('id'))
            ?: $this->params()->fromQuery('id');

        if (!$id) {
            $message = new PsrMessage('No mapping id set.');
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/mapper/default', ['action' => 'browse']);
        }

        if (is_numeric($id)) {
            try {
                $entity = $this->api()->read('mappers', ['id' => $id])->getContent();
            } catch (\Throwable $e) {
                $entity = null;
            }
        } else {
            $internalMappings = $this->mapperConfigList()->getInternalMappings();
            $entity = isset($internalMappings[$id]) ? $id : null;
        }

        if (!$entity) {
            $message = new PsrMessage('Mapping #{mapping_id} does not exist', ['mapping_id' => $id]);
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/mapper/default', ['action' => 'browse']);
        }

        return $entity;
    }

    /**
     * Clean label by removing file extensions.
     */
    protected function cleanLabel(string $label): string
    {
        $extensions = [
            '.ini',
            '.json',
            '.jmespath',
            '.jsondot',
            '.jsonpath',
            '.xml',
            '.xsl',
            '.xslt',
            '.xslt1',
            '.xslt2',
            '.xslt3',
        ];
        return str_ireplace($extensions, '', $label);
    }
}
