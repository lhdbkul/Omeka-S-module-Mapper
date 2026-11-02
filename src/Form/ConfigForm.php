<?php declare(strict_types=1);

namespace Mapper\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Mapper\Module;

class ConfigForm extends Form
{
    public function init(): void
    {
        $detected = Module::detectXsltCommand();
        $detectedLabel = $detected
            ? sprintf('Detected on this server: %s', $detected) // @translate
            : 'No external xslt processor detected on this server. Install libsaxonhe-java (Debian/Ubuntu) or saxon (Fedora/RHEL) for xslt 2/3 support.'; // @translate

        $this
            ->add([
                'name' => 'mapper_xslt_processor_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'XSLT processor', // @translate
                    'info' => $detectedLabel,
                    'value_options' => [
                        'auto' => 'Auto-detect (recommended)', // @translate
                        'custom' => 'Custom command', // @translate
                        'disabled' => 'Disabled (use PHP internal xslt 1)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mapper_xslt_processor_mode',
                    'value' => 'auto',
                ],
            ])
            ->add([
                'name' => 'mapper_xslt_processor',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Custom xslt processor command', // @translate
                    'info' => 'Used only when mode is "Custom". Use "%1$s" (input), "%2$s" (stylesheet), "%3$s" (output), unescaped.', // @translate
                ],
                'attributes' => [
                    'id' => 'mapper_xslt_processor',
                    'placeholder' => $detected
                        ?: 'CLASSPATH=/usr/share/java/Saxon-HE.jar java net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s',
                ],
            ])
            ->add([
                'name' => 'mapper_xslt_timeout',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Max duration of the external xslt processor (seconds)', // @translate
                    'info' => 'Set 0 for no limit.', // @translate
                ],
                'attributes' => [
                    'id' => 'mapper_xslt_timeout',
                    'min' => 0,
                    'value' => 600,
                ],
            ])
            ->add([
                'name' => 'mapper_download_max_size',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Max size of a remote file (megabytes)', // @translate
                    'info' => 'Limit of the remote xml files to import. Set 0 for no limit.', // @translate
                ],
                'attributes' => [
                    'id' => 'mapper_download_max_size',
                    'min' => 0,
                    'value' => 512,
                ],
            ])
            ->add([
                'name' => 'mapper_download_timeout',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Max idle duration of a download (seconds)', // @translate
                    'info' => 'Set 0 to use the default value of php.', // @translate
                ],
                'attributes' => [
                    'id' => 'mapper_download_timeout',
                    'min' => 0,
                    'value' => 60,
                ],
            ])
        ;

        $inputFilter = $this->getInputFilter();
        foreach (['mapper_xslt_timeout', 'mapper_download_max_size', 'mapper_download_timeout'] as $name) {
            $inputFilter->add([
                'name' => $name,
                'required' => false,
            ]);
        }
    }
}
